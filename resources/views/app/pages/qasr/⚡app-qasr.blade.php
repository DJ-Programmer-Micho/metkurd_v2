<?php

use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use App\Models\Tool;
use App\Models\ToolAction;
use App\Models\MlJob;
use App\Support\AppToolCatalog;

use App\Services\Providers\RunPodProvider;
use App\Services\Billing\CreditService;
use App\Services\Storage\CustomerOutputStorage;
use App\Services\ASR\QasrJobSyncService;
use App\Services\Security\JobExecutionLockService;
use App\Services\Media\AudioProbeService;

new
#[Layout('app::layouts.app')]
class extends Component
{
    use WithPagination;
    use WithFileUploads;
    use \App\Support\Plans\ResolvesConcurrentJobLimit;

    protected $paginationTheme = 'bootstrap';

    protected string $toolCode = 'qasr';
    protected string $actionCode = 'standard';
    protected string $fullActionCode = 'qasr.standard';

    #[Url(as: 'page', except: 1)]
    public int $page = 1;

    // =========================================================
    // UI State
    // =========================================================
    public ?string $currentJobId = null;
    public ?string $providerJobId = null;
    public ?string $currentStatus = null;
    public bool $jobFinished = false;
    public bool $showJobStatus = false;
    public int $currentProgress = 0;
    public ?string $dismissedJobStatusFor = null;

    public bool $showEliminateModal = false;

    // =========================================================
    // Inputs
    // =========================================================
    public string $modelVariant = 'fine_tuned';

    public $audioFile = null;
    public ?string $audioFileName = null;
    public ?int $audioFileBytes = null;
    public ?string $audioFileMime = null;
    public ?float $audioDurationSec = null;
    public ?float $audioDurationMin = null;
    public int $audioBillableMin = 0;
    public ?string $audioExt = null;
    public ?string $audioHash = null;

    // =========================================================
    // Output
    // =========================================================
    public string $transcriptionText = '';
    public ?string $latestFinishedJobId = null;

    // =========================================================
    // Credits UI
    // =========================================================
    public int $walletBalance = 0;
    public int $creditsCost = 0;

    // =========================================================
    // Internal
    // =========================================================
    public int $transcriptionsRefreshKey = 0;

    public array $modelVariantOptions = [
        'fine_tuned' => 'Fine Tuned',
    ];

    // =========================================================
    // Lifecycle
    // =========================================================
    public function mount(): void
    {
        $this->syncWallet();
        $this->syncCostPreview();
        $this->dismissedJobStatusFor = session('qasr.dismissed_job_status_for');
        $this->hydrateCurrentJobFromDb();
        $this->hydrateLatestFinishedResult();
    }

    #[On('header:refresh')]
    #[On('customerPlanUpdated')]
    #[On('customerStorageUpdated')]
    #[On('qasr-transcriptions-refresh')]
    #[On('qasr-renders-refresh')]
    #[On('asr-renders-refresh')]
    public function refreshUi(): void
    {
        $this->syncWallet();
        $this->syncCostPreview();
        $this->hydrateCurrentJobFromDb();
        $this->hydrateLatestFinishedResult();
        $this->transcriptionsRefreshKey++;
    }

    // =========================================================
    // Watchers
    // =========================================================
    public function updatedModelVariant(): void
    {
        $this->syncCostPreview();
    }

    public function updatedAudioFile(AudioProbeService $probe): void
    {
        $this->validateOnly('audioFile');

        if (!$this->audioFile) {
            return;
        }

        try {
            $info = $probe->probeUploadedFile($this->audioFile);

            $this->audioFileName    = $this->audioFile->getClientOriginalName();
            $this->audioFileBytes   = (int) $this->audioFile->getSize();
            $this->audioFileMime    = $this->audioFile->getMimeType() ?: 'audio/*';
            $this->audioDurationSec = (float) $info['duration_sec'];
            $this->audioDurationMin = (float) $info['duration_min'];
            $this->audioBillableMin = (int) $info['billable_min'];
            $this->audioExt         = (string) $info['audio_ext'];

            $realPath = $this->audioFile->getRealPath();
            $this->audioHash = $realPath && is_file($realPath)
                ? hash_file('sha256', $realPath)
                : sha1(($this->audioFileName ?? '') . '|' . ($this->audioFileBytes ?? 0));

            $this->syncCostPreview();
        } catch (\Throwable $e) {
            Log::error('QASR_AUDIO_UPLOAD_FAIL', [
                'message' => $e->getMessage(),
            ]);

            $this->removeAudioFile();
            $this->dispatch('alert', type: 'error', message: __('Failed to inspect the uploaded audio file.'));
        }
    }

    public function removeAudioFile(): void
    {
        $this->audioFile = null;
        $this->audioFileName = null;
        $this->audioFileBytes = null;
        $this->audioFileMime = null;
        $this->audioDurationSec = null;
        $this->audioDurationMin = null;
        $this->audioBillableMin = 0;
        $this->audioExt = null;
        $this->audioHash = null;
        $this->creditsCost = 0;

        $this->dispatch('qasr-audio-file-cleared');
    }

    // =========================================================
    // Computed
    // =========================================================
    #[Computed]
    public function canTranscribe(): bool
    {
        return $this->transcribeBlockedReason === null;
    }

    #[Computed]
    public function transcribeBlockedReason(): ?string
    {
        if ($this->isProcessing()) {
            return __('A transcription is already in progress.');
        }

        if ($this->currentActiveJobsCount() >= $this->allowedConcurrentJobs()) {
            return __('You reached your concurrent job limit for the current plan.');
        }

        if (!$this->audioFile) {
            return __('Please upload an audio file.');
        }

        if ($this->audioBillableMin <= 0) {
            return __('Could not determine audio duration.');
        }

        if ($this->creditsCost <= 0) {
            return __('Pricing could not be calculated.');
        }

        if ($this->walletBalance < $this->creditsCost) {
            return __('Not enough credits.');
        }

        return null;
    }

    #[Computed]
    public function transcriptions()
    {
        $this->transcriptionsRefreshKey;

        $customerId = auth('app')->id();
        $locale = app()->getLocale();

        if (!$customerId) {
            return MlJob::query()->whereRaw('1=0')->paginate(5);
        }

        $paginator = MlJob::query()
            ->where('customer_id', $customerId)
            ->when(app(AppToolCatalog::class)->toolId($this->toolCode), fn ($q, $toolId) => $q->where('tool_id', $toolId))
            ->whereIn('status', ['done', 'delete_failed', 'deleted'])
            ->orderByDesc('finished_at')
            ->paginate(5);

        $paginator->setCollection(
            $paginator->getCollection()->values()->map(function ($j, $index) use ($locale) {
                $jobId = (string) $j->id;
                $text = (string) data_get($j->output, 'text', '');

                $snippet = mb_strlen($text) > 280
                    ? mb_substr($text, 0, 220) . '...'
                    : $text;

            return [
                'id'              => $jobId,
                'audio_name'      => data_get($j->input, 'audio_name', __('Uploaded Audio')),
                'model_variant'   => data_get($j->input, 'model_variant', 'fine_tuned'),
                'created_at'      => optional($j->finished_at ?? $j->created_at)->format('Y-m-d H:i'),
                'text'            => $text,
                'snippet'         => $snippet,
                'word_count'      => (int) data_get($j->output, 'word_count', 0),
                'char_count'      => (int) data_get($j->output, 'char_count', mb_strlen($text)),
                'duration_mins'   => (float) data_get($j->input, 'audio_duration_min', 0),
                'credits_charged' => (int) ($j->credits_charged ?? 0),
                'download_url'    => route('app.renders.qasr.txt', [
                    'locale' => $locale,
                    'jobId'  => $jobId,
                ]),
                'audio_url'       => route('app.renders.qasr.input-audio', [
                    'locale' => $locale,
                    'jobId'  => $jobId,
                ]) . '?proxy=1',
                'is_latest'       => $index === 0,
            ];
            })
        );

        return $paginator;
    }

    // =========================================================
    // Billing / Wallet
    // =========================================================
    protected function syncWallet(): void
    {
        $c = auth('app')->user();
        $wallet = $c?->wallet()->first();

        $subscription = (int) ($wallet?->subscription_balance_credits ?? 0);
        $addon = (int) ($wallet?->addon_balance_credits ?? 0);

        $this->walletBalance = $subscription + $addon;
    }

    protected function syncCostPreview(): void
    {
        $c = auth('app')->user();

        if (!$c || !$this->audioFile || $this->audioBillableMin <= 0) {
            $this->creditsCost = 0;
            return;
        }

        if (method_exists($c, 'priceCreditsFor')) {
            $this->creditsCost = (int) $c->priceCreditsFor($this->fullActionCode, [
                'minutes'     => $this->audioBillableMin,
                'metric_code' => 'minute',
                'model_variant' => $this->modelVariant,
            ]);
            return;
        }

        $this->creditsCost = $this->audioBillableMin * 1000;
    }

    protected function currentActiveJobsCount(): int
    {
        $customerId = auth('app')->id();
        if (!$customerId) {
            return 0;
        }

        return MlJob::query()
            ->where('customer_id', $customerId)
            ->whereIn('job_kind', ['asr', 'wasr', 'qasr'])
            ->whereIn('status', ['queued', 'running', 'saving'])
            ->whereNotNull('lock_expires_at')
            ->where('lock_expires_at', '>', now())
            ->count();
    }

    // =========================================================
    // Rules
    // =========================================================
    protected function rules(): array
    {
        return [
            'audioFile'     => 'required|file|mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,audio/aac,audio/ogg,audio/webm,audio/flac,audio/x-flac|max:102400',
            'modelVariant'  => 'required|string|in:fine_tuned',
        ];
    }

    // =========================================================
    // Helpers
    // =========================================================
    protected function isProcessing(): bool
    {
        return (bool) $this->currentJobId
            && !$this->jobFinished
            && in_array($this->currentStatus, ['queued', 'running', 'saving'], true);
    }

    protected function findToolAndAction(): array
    {
        $tool = Tool::query()
            ->where('code', $this->toolCode)
            ->first();

        $action = ToolAction::query()
            ->where('full_code', $this->fullActionCode)
            ->first();

        if (!$tool || !$action) {
            throw new \RuntimeException("Tool or ToolAction missing ({$this->toolCode} / {$this->fullActionCode}).");
        }

        return [$tool, $action];
    }

    protected function currentFolderForCustomer($c): string
    {
        return \App\Support\CustomerFolder::make(
            (int) $c->id,
            $c->profile?->first_name ?? $c->first_name ?? null,
            $c->profile?->last_name ?? $c->last_name ?? null,
            $c->username ?? null
        );
    }

    // =========================================================
    // Hydration
    // =========================================================
    public function hideJobStatus(): void
    {
        if ($this->currentJobId) {
            $this->dismissedJobStatusFor = $this->currentJobId;
            session(['qasr.dismissed_job_status_for' => $this->currentJobId]);
        }

        $this->showJobStatus = false;
        $this->currentJobId = null;
        $this->providerJobId = null;
        $this->currentStatus = null;
        $this->jobFinished = false;
        $this->currentProgress = 0;
    }

    protected function hydrateCurrentJobFromDb(): void
    {
        $customerId = auth('app')->id();
        if (!$customerId) {
            return;
        }

        $job = MlJob::query()
            ->where('customer_id', $customerId)
            ->when(app(AppToolCatalog::class)->toolId($this->toolCode), fn ($q, $toolId) => $q->where('tool_id', $toolId))
            ->where(function ($q) {
                $q->where(function ($q1) {
                    $q1->whereIn('status', ['queued', 'running', 'saving'])
                        ->whereNotNull('lock_expires_at')
                        ->where('lock_expires_at', '>', now());
                })->orWhere(function ($q2) {
                    $q2->whereIn('status', ['done', 'failed'])
                        ->where('finished_at', '>=', now()->subSeconds(3));
                });
            })
            ->orderByRaw("
                CASE
                    WHEN status IN ('queued','running','saving') THEN 0
                    WHEN status = 'done' THEN 1
                    WHEN status = 'failed' THEN 2
                    ELSE 3
                END
            ")
            ->orderByDesc('updated_at')
            ->first();

        if (!$job) {
            $this->currentJobId = null;
            $this->providerJobId = null;
            $this->currentStatus = null;
            $this->jobFinished = false;
            $this->showJobStatus = false;
            $this->currentProgress = 0;
            return;
        }

        $status = (string) $job->status;

        $this->currentJobId = (string) $job->id;
        $this->providerJobId = (string) ($job->provider_job_id ?? '');
        $this->currentStatus = $status;
        $this->jobFinished = in_array($status, ['done', 'failed', 'deleted'], true);

        $this->currentProgress = match ($status) {
            'queued'  => 10,
            'running' => 45,
            'saving'  => 90,
            'done'    => 100,
            'failed'  => 100,
            'deleted' => 100,
            default   => 0,
        };

        if ($status === 'done') {
            $this->transcriptionText = (string) data_get($job->output, 'text', '');
            $this->latestFinishedJobId = (string) $job->id;
        }

        if (!$this->jobFinished) {
            $this->showJobStatus = true;
            return;
        }

        $finishedAt = $job->finished_at;
        $this->showJobStatus =
            $this->dismissedJobStatusFor !== (string) $job->id
            && $finishedAt
            && $finishedAt->gte(now()->subSeconds(3));
    }

    protected function hydrateLatestFinishedResult(): void
    {
        $customerId = auth('app')->id();
        if (!$customerId) {
            return;
        }

        $job = MlJob::query()
            ->where('customer_id', $customerId)
            ->when(app(AppToolCatalog::class)->toolId($this->toolCode), fn ($q, $toolId) => $q->where('tool_id', $toolId))
            ->where('status', 'done')
            ->orderByDesc('finished_at')
            ->first();

        if ($job) {
            $this->latestFinishedJobId = (string) $job->id;

            if ($this->transcriptionText === '') {
                $this->transcriptionText = (string) data_get($job->output, 'text', '');
            }
        }
    }

    // =========================================================
    // Actions
    // =========================================================
    public function postQasr(
        RunPodProvider $runpod,
        CreditService $credits,
        CustomerOutputStorage $storage,
        JobExecutionLockService $locks
    ): void {
        $this->showJobStatus = true;
        $this->hydrateCurrentJobFromDb();

        if ($this->isProcessing()) {
            $this->dispatch('alert', type: 'warning', message: __('A transcription is already in progress.'));
            return;
        }

        $this->validate();

        $customer = auth('app')->user();
        if (!$customer) {
            $this->dispatch('alert', type: 'error', message: __('You must be logged in.'));
            return;
        }

        [$tool, $action] = $this->findToolAndAction();

        if (method_exists($customer, 'isAllowed') && !$customer->isAllowed($action->full_code)) {
            $this->dispatch('alert', type: 'error', message: __('Your plan does not allow QASR.'));
            return;
        }

        if ($this->creditsCost <= 0 || $this->audioBillableMin <= 0) {
            $this->dispatch('alert', type: 'error', message: __('Could not calculate billing for this file.'));
            return;
        }

        try {
            $credits->charge((int) $customer->id, $this->creditsCost, 'asr_charge', [
                'related_type' => 'ml_job',
                'related_id'   => null,
                'tool_action'  => $action->full_code,
                'minutes'      => $this->audioBillableMin,
                'seconds'      => $this->audioDurationSec,
                'model_variant' => $this->modelVariant,
            ]);
        } catch (\Throwable $e) {
            $this->syncWallet();
            $this->dispatch('alert', type: 'error', message: __('Not enough credits.'));
            return;
        }

        $jobId = (string) Str::uuid();
        $savedAudio = null;

        try {
            $customerFresh = auth('app')->user()->loadMissing('profile');
            $folder = $this->currentFolderForCustomer($customerFresh);

            $audioExt = strtolower((string) ($this->audioExt ?: $this->audioFile?->getClientOriginalExtension() ?: 'wav'));
            $audioKey = "renders/{$folder}/qasr/{$jobId}/audio.wav";

            DB::transaction(function () use ($jobId, $tool, $action, $customer) {
                MlJob::create([
                    'id'               => $jobId,
                    'customer_id'      => (int) $customer->id,
                    'tool_id'          => (int) $tool->id,
                    'tool_action_id'   => (int) $action->id,
                    'job_kind'         => 'qasr',
                    'status'           => 'queued',
                    'provider'         => 'runpod',
                    'provider_job_id'  => null,
                    'input_hash'       => $this->audioHash,
                    'credits_charged'  => (int) $this->creditsCost,
                    'input'            => [
                        'model_variant'      => $this->modelVariant,
                        'audio_name'         => $this->audioFileName,
                        'audio_mime'         => $this->audioFileMime,
                        'audio_bytes'        => $this->audioFileBytes,
                        'audio_duration_sec' => $this->audioDurationSec,
                        'audio_duration_min' => $this->audioDurationMin,
                        'audio_billable_min' => $this->audioBillableMin,
                    ],
                    'output'           => null,
                    'error'            => null,
                    'started_at'       => now(),
                    'finished_at'      => null,
                    'storage_in_bytes' => 0,
                    'storage_out_bytes'=> 0,
                ]);
            }, 3);

            $savedAudio = $storage->saveUploadedFileToS3(
                (int) $customer->id,
                $this->audioFile,
                $audioKey,
                [
                    'job_id'        => $jobId,
                    'tool'          => 'qasr',
                    'purpose'       => 'input_audio',
                    'role'          => 'source_audio',
                    'checksum'      => $this->audioHash,
                    'original_name' => $this->audioFileName,
                ]
            );

            $audioUrl = $storage->temporaryUrl($savedAudio['path'], 120, [
                'ResponseContentType' => $savedAudio['mime'] ?? $this->audioFileMime,
            ]);

            MlJob::query()->where('id', $jobId)->update([
                'input' => array_merge((array) (MlJob::find($jobId)?->input ?? []), [
                    'audio_disk' => $savedAudio['disk'],
                    'audio_path' => $savedAudio['path'],
                    'audio_url'  => $audioUrl,
                    'audio_ext'  => $audioExt,
                ]),
                'storage_in_bytes' => (int) $savedAudio['bytes'],
            ]);

            $lock = $locks->acquireAsrLock(
                (int) $customer->id,
                $jobId,
                (string) $this->audioHash,
                request()->session(),
                request()->userAgent(),
                request()->ip(),
                'qasr'
            );

            if (!($lock['ok'] ?? false)) {
                throw new \RuntimeException((string) ($lock['message'] ?? __('Could not acquire ASR lock.')));
            }

            $endpointId = (string) (
                data_get($tool->meta, 'runpod_endpoint_id')
                ?: config('runpod.endpoints.qasr')
                ?: env('RUNPOD_ENDPOINT_ID_QASR')
            );

            if ($endpointId === '') {
                throw new \RuntimeException(__('RUNPOD_ENDPOINT_ID_QASR is missing.'));
            }

            $timeout = (int) (data_get($tool->meta, 'runpod_timeout') ?: config('runpod.timeout', 60));

            $resp = $runpod->run($endpointId, [
                'audio_url'      => $audioUrl,
                'model_variant'  => $this->modelVariant,
            ], $timeout);

            $providerJobId = (string) data_get($resp, 'id', '');
            if ($providerJobId === '') {
                throw new \RuntimeException(__('RunPod did not return a job ID.'));
            }

            MlJob::query()->where('id', $jobId)->update([
                'status'          => 'running',
                'provider_job_id' => $providerJobId,
                'updated_at'      => now(),
            ]);

            $this->currentJobId = $jobId;
            $this->providerJobId = $providerJobId;
            $this->currentStatus = 'running';
            $this->jobFinished = false;
            $this->currentProgress = 15;
            $this->showJobStatus = true;

            $this->dispatch('header:refresh');
            $this->dispatch('customerPlanUpdated');
            $this->dispatch('customerStorageUpdated');
            $this->dispatch('qasr-transcriptions-refresh');
            $this->dispatch('qasr-renders-refresh');
            $this->dispatch('alert', type: 'info', message: __('QASR job started.'));

            $this->syncWallet();
        } catch (\Throwable $e) {
            Log::warning('QASR_START_FAIL', [
                'job_id' => $jobId,
                'error'  => $e->getMessage(),
            ]);

            try {
                if ($savedAudio && !empty($savedAudio['path'])) {
                    $storage->deleteFromS3AndUncount(
                        (int) $customer->id,
                        (string) $savedAudio['path'],
                        (int) ($savedAudio['bytes'] ?? 0)
                    );
                }
            } catch (\Throwable $cleanup) {
                Log::warning('QASR_START_CLEANUP_FAIL', [
                    'job_id' => $jobId,
                    'error'  => $cleanup->getMessage(),
                ]);
            }

            MlJob::query()->where('id', $jobId)->update([
                'status'      => 'failed',
                'error'       => ['message' => $e->getMessage()],
                'finished_at' => now(),
                'updated_at'  => now(),
            ]);

            $locks->releaseLock($jobId);

            $this->dispatch('alert', type: 'error', message: $e->getMessage());
            $this->syncWallet();
            $this->hydrateCurrentJobFromDb();
        }
    }

    public function pollJob(QasrJobSyncService $sync): void
    {
        if (!$this->currentJobId) {
            return;
        }

        $job = MlJob::query()
            ->with('tool')
            ->where('id', $this->currentJobId)
            ->where('customer_id', auth('app')->id())
            ->first();

        if (!$job || !$job->tool) {
            return;
        }

        try {
            $result = $sync->sync($job, $job->tool);

            $this->currentStatus = (string) ($result['status'] ?? $this->currentStatus);
            $this->currentProgress = (int) ($result['progress'] ?? $this->currentProgress);

            if (($result['done'] ?? false) === true) {
                $this->jobFinished = true;
                $this->transcriptionText = (string) ($result['text'] ?? '');
                $this->showJobStatus = true;
                $this->latestFinishedJobId = $this->currentJobId;
                $this->hydrateLatestFinishedResult();

                $this->dispatch('customerPlanUpdated');
                $this->dispatch('customerStorageUpdated');
                $this->dispatch('qasr-transcriptions-refresh');
                $this->dispatch('qasr-renders-refresh');
                $this->dispatch('header:refresh');
                $this->dispatch('alert', type: 'success', message: __('Transcription completed.'));
            }

            if (($result['failed'] ?? false) === true) {
                $this->jobFinished = true;
                $this->showJobStatus = true;

                $msg = (string) ($result['message'] ?? __('Transcription failed.'));
                $this->dispatch('header:refresh');
                $this->dispatch('alert', type: 'error', message: $msg);
            }
        } catch (\Throwable $e) {
            Log::warning('QASR_POLL_FAIL', [
                'job_id' => $this->currentJobId,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    public function copyTranscript(): void
    {
        if (trim($this->transcriptionText) === '') {
            $this->dispatch('alert', type: 'warning', message: __('No transcription to copy.'));
            return;
        }

        $this->dispatch('qasr-copy-text', text: $this->transcriptionText);
        $this->dispatch('alert', type: 'success', message: __('Transcription copied.'));
    }

    public function openEliminateModal(): void
    {
        $this->showEliminateModal = true;
    }

    public function closeEliminateModal(): void
    {
        $this->showEliminateModal = false;
    }

    public function eliminateCurrentJob(JobExecutionLockService $locks, CustomerOutputStorage $storage): void
    {
        $this->closeEliminateModal();

        if (!$this->currentJobId) {
            return;
        }

        $customerId = auth('app')->id();

        $job = MlJob::query()
            ->where('id', $this->currentJobId)
            ->where('customer_id', $customerId)
            ->first();

        if ($job && in_array((string) $job->status, ['queued', 'running', 'saving'], true)) {
            $job->update([
                'status' => 'failed',
                'error' => [
                    'message' => __('Eliminated by customer. Credits are not refundable.'),
                    'type' => 'eliminated_by_customer',
                ],
                'finished_at' => now(),
            ]);

            $audioPath  = (string) data_get($job->input, 'audio_path', '');
            $audioBytes = (int) ((int) $job->storage_in_bytes ?: data_get($job->input, 'audio_bytes', 0));

            try {
                if ($audioPath !== '') {
                    $storage->deleteFromS3AndUncount((int) $customerId, $audioPath, $audioBytes);
                }
            } catch (\Throwable $e) {
                Log::warning('QASR_ELIMINATE_AUDIO_DELETE_FAIL', [
                    'job_id' => (string) $job->id,
                    'path'   => $audioPath,
                    'error'  => $e->getMessage(),
                ]);
            }

            $locks->releaseLock((string) $job->id);
        }

        $this->currentJobId = null;
        $this->providerJobId = null;
        $this->currentStatus = null;
        $this->jobFinished = true;
        $this->showJobStatus = false;
        $this->currentProgress = 0;

        $this->dispatch('header:refresh');
        $this->dispatch('qasr-transcriptions-refresh');
        $this->dispatch('qasr-renders-refresh');
        $this->dispatch('alert', type: 'warning', message: __('Current QASR job eliminated. Credits were not refunded.'));
    }

    public function deleteTranscription(string $jobId, QasrJobSyncService $sync): void
    {
        $job = MlJob::query()
            ->where('id', $jobId)
            ->where('customer_id', auth('app')->id())
            ->firstOrFail();

        $sync->deleteFinishedTranscription($job);

        if ($this->latestFinishedJobId === $jobId) {
            $this->latestFinishedJobId = null;
            $this->transcriptionText = '';
        }

        $this->dispatch('customerStorageUpdated');
        $this->dispatch('header:refresh');
        $this->dispatch('qasr-transcriptions-refresh');
        $this->dispatch('qasr-renders-refresh');
        $this->dispatch('alert', type: 'success', message: __('Transcription deleted.'));
    }

    public function resetForm(): void
    {
        $this->removeAudioFile();

        $this->modelVariant = 'fine_tuned';

        $this->dispatch('qasr-form-reset');
    }

    public function render()
    {
        return view('app.pages.qasr.⚡app-qasr');
    }
};
?>

<x-slot:title>{{ __('QASR') }} | {{ __('MET KURD') }}</x-slot:title>

<div id="qasr-page-root">
    @if($currentJobId && !$jobFinished)
        <div wire:poll.visible.7000ms="pollJob"></div>
    @endif

    @php
        $status = match($currentStatus) {
            'queued' => __('Queued'),
            'running' => __('Running'),
            'saving' => __('Saving'),
            'done' => __('Done'),
            'failed' => __('Failed'),
            default => __('Idle'),
        };
        $badge = match($currentStatus) {
            'queued'  => 'warning',
            'running' => 'info',
            'saving'  => 'primary',
            'done'    => 'success',
            'failed'  => 'danger',
            default   => 'secondary',
        };
        $glassClass = match($currentStatus) {
            'queued'  => 'glass-load--warning',
            'running' => 'glass-load--info',
            'saving'  => 'glass-load--info',
            'done'    => 'glass-load--success',
            'failed'  => 'glass-load--danger',
            default   => 'glass-load--secondary',
        };
        $progress = (int) ($currentProgress ?? 0);
    @endphp

    <div class="row g-3">
        <div class="col-12">
            @if($showJobStatus && $currentJobId && $currentStatus)
                <div class="glass-load {{ $glassClass }} p-3">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                        <div>
                            <div class="fw-semibold">{{ __('ASR Transcription Status') }}</div>
                            <div class="small text-muted">{{ __('Job ID:') }} {{ $currentJobId ?: '-' }}</div>
                        </div>
                        <span class="badge text-bg-{{ $badge }} fs-6 px-3 py-2">{{ $status }}</span>
                    </div>

                    <div class="progress" role="progressbar"
                         aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100"
                         style="height:8px;">
                        <div class="progress-bar progress-bar-striped {{ !$jobFinished ? 'progress-bar-animated' : '' }} bg-{{ $badge }}"
                             style="width: {{ $progress }}%"></div>
                    </div>

                    <div class="d-flex align-items-center justify-content-between mt-2 small">
                        <span class="text-muted">{{ $progress }}%</span>
                        @if($jobFinished)
                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="hideJobStatus">{{ __('Hide') }}</button>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-7">
            <div class="turbo-border mb-3">
                <div class="turbo-inner">
                    <div class="card mb-0">
                        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3">
                            <div>
                                <strong>{{ __('QASR (QWEN AUTOMATIC-SPEECH-RECOGNITION)') }}</strong>
                                <div class="text-muted small">{{ __('Upload your audio and generate a full transcription with the Qwen ASR engine') }}</div>
                            </div>

                            <div class="d-flex gap-2 flex-wrap text-end small">
                                <div class="mini-stat">
                                    <div class="text-muted">{{ __('Wallet') }}</div>
                                    <div class="fw-semibold">{{ number_format($walletBalance) }}</div>
                                </div>

                                <div class="mini-stat">
                                    <div class="text-muted">{{ __('Cost') }}</div>
                                    <div class="fw-semibold">{{ number_format($creditsCost) }}</div>
                                </div>

                                @if($audioDurationMin)
                                    <div class="mini-stat">
                                        <div class="text-muted">{{ __('Minutes') }}</div>
                                        <div class="fw-semibold">{{ number_format((float) $audioDurationMin, 2) }}</div>
                                    </div>
                                @endif

                                @if($audioBillableMin > 0)
                                    <div class="mini-stat">
                                        <div class="text-muted">{{ __('Billable') }}</div>
                                        <div class="fw-semibold">{{ $audioBillableMin }}</div>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">{{ __('Audio File') }}</label>

                                <div wire:ignore>
                                    <input
                                        type="file"
                                        id="qasr-audio-pond"
                                        accept=".wav,.mp3,.m4a,.aac,.ogg,.flac,.webm,audio/*"
                                    >
                                </div>

                                <div class="small text-muted mt-2">
                                    {{ __('Supported: WAV, MP3, M4A, AAC, OGG, FLAC, WebM - max 100 MB') }}
                                </div>

                                <div wire:loading wire:target="audioFile" class="small text-primary mt-2">
                                    {{ __('Uploading audio...') }}
                                </div>

                                @if($audioFileName)
                                    <div class="border rounded p-2 mt-2 bg-success-subtle">
                                        <div class="fw-semibold small">{{ $audioFileName }}</div>
                                        <div class="small text-muted">
                                            {{ $audioFileMime ?: 'audio/*' }}

                                            @if($audioFileBytes)
                                                | {{ __('Size: :bytes bytes', ['bytes' => number_format($audioFileBytes)]) }}
                                            @endif

                                            @if($audioDurationMin)
                                                | {{ __('Duration: :minutes min', ['minutes' => number_format((float) $audioDurationMin, 2)]) }}
                                            @endif
                                        </div>

                                        <div class="mt-2">
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-danger"
                                                wire:click="removeAudioFile"
                                            >
                                                {{ __('Remove file') }}
                                            </button>
                                        </div>
                                    </div>
                                @endif

                                @error('audioFile')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            <hr>

                            <div class="row g-3 align-items-end mb-1">
                                <div class="col-md-6">
                                    <label class="form-label">{{ __('Model Variant') }}</label>
                                    <select class="form-select" wire:model.change="modelVariant">
                                        @foreach($modelVariantOptions as $code => $label)
                                            <option value="{{ $code }}">{{ __($label) }}</option>
                                        @endforeach
                                    </select>
                                    @error('modelVariant')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            @if($audioDurationMin && $creditsCost > 0)
                                <div class="qasr-cost-preview rounded-3 p-3 mt-3 small">
                                    {{ __('This Qwen ASR request will transcribe about :minutes billable minute(s) using the :variant model.', [
                                        'minutes' => number_format((float) $audioDurationMin, 2),
                                        'variant' => __($modelVariantOptions[$modelVariant] ?? $modelVariant),
                                    ]) }}
                                </div>
                            @endif

                            <div class="d-flex gap-2 mt-4 flex-wrap">
                                <button
                                    class="btn {{ $this->canTranscribe ? 'btn-primary' : 'btn-danger' }}"
                                    wire:click="postQasr"
                                    wire:loading.attr="disabled"
                                    wire:target="postQasr,audioFile"
                                    @disabled(!$this->canTranscribe)
                                    type="button"
                                    id="btn-qasr-transcribe"
                                >
                                    <span wire:loading.remove wire:target="postQasr,audioFile">
                                        {{ $this->canTranscribe ? __('Transcribe') : ($this->transcribeBlockedReason ?? __('Transcribe')) }}
                                    </span>

                                    <span wire:loading wire:target="audioFile">
                                        <span class="spinner-border spinner-border-sm me-1"></span>
                                        {{ __('Uploading audio...') }}
                                    </span>

                                    <span wire:loading wire:target="postQasr">
                                        <span class="spinner-border spinner-border-sm me-1"></span>
                                        {{ __('Starting...') }}
                                    </span>
                                </button>

                                <button class="btn btn-outline-secondary" wire:click="resetForm" type="button">
                                    {{ __('Reset') }}
                                </button>

                                <button
                                    class="btn btn-outline-danger"
                                    wire:click="openEliminateModal"
                                    type="button"
                                    @disabled(!$currentJobId || $jobFinished)
                                >
                                    {{ __('Eliminate') }}
                                </button>

                                @if($walletBalance < $creditsCost && $creditsCost > 0)
                                    <span class="small text-danger align-self-center">
                                        {{ __('Not enough credits for this transcription.') }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="turbo-border mb-3">
                <div class="turbo-inner">
                    <div class="card mb-0">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-3">
                            <div>
                                <strong>{{ __('Recent Transcriptions') }}</strong>
                                <div class="small text-muted">{{ __('Your latest speech-to-text outputs in one place.') }}</div>
                            </div>

                            <div class="d-flex gap-2">
                                @if($latestFinishedJobId)
                                    <button class="btn btn-sm btn-outline-primary" wire:click="copyTranscript" type="button">
                                        {{ __('Copy') }}
                                    </button>
                                @endif

                                <button class="btn btn-sm btn-outline-secondary" wire:click="$refresh" type="button">
                                    {{ __('Refresh') }}
                                </button>
                            </div>
                        </div>

                        <div class="card-body">
                            @if($transcriptionText !== '')
                                <div class="qasr-latest-panel mb-4">
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
                                        <div>
                                            <div class="qasr-section-caption">{{ __('Latest Result') }}</div>
                                            <div class="fw-semibold">{{ __('Ready to copy or export') }}</div>
                                        </div>

                                        @if($latestFinishedJobId)
                                            <div class="d-flex gap-2 flex-wrap">
                                                <button class="btn btn-sm btn-primary" wire:click="copyTranscript" type="button">
                                                    {{ __('Copy Transcript') }}
                                                </button>

                                                <a
                                                    class="btn btn-sm btn-outline-success"
                                                    href="{{ route('app.renders.qasr.txt', ['locale' => app()->getLocale(), 'jobId' => $latestFinishedJobId]) }}"
                                                >
                                                    {{ __('Download TXT') }}
                                                </a>
                                            </div>
                                        @endif
                                    </div>

                                    <div class="qasr-output-text rounded-3 p-3 text-right">
                                        {{ $transcriptionText }}
                                    </div>
                                </div>
                            @endif

                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                <div>
                                    <div class="qasr-section-caption">{{ __('History') }}</div>
                                    <div class="small text-muted">
                                        {{ $this->transcriptions->count() }}
                                        {{ $this->transcriptions->count() === 1 ? 'transcription' : 'transcriptions' }}
                                        on this page
                                    </div>
                                </div>
                            </div>

                            @if($this->transcriptions->count() === 0)
                                <div class="qasr-empty-state text-center">
                                    <div class="fw-semibold mb-1">{{ __('No transcriptions yet') }}</div>
                                    <div class="small text-muted">{{ __('Upload an audio file and your transcription history will appear here.') }}</div>
                                </div>
                            @else
                                @foreach($this->transcriptions as $r)
                                    <div
                                        class="render-card qasr-transcript-item {{ $r['is_latest'] ? 'qasr-transcript-item--latest' : '' }} mb-1 p-1 rounded-3 border"
                                        wire:key="qasr-render-{{ $r['id'] }}"
                                        id="qasr-render-card-{{ $r['id'] }}"
                                    >
                                        <div class="d-flex justify-content-between align-items-start gap-3 p-1 border">
                                            <div class="min-w-0 flex-grow-1">
                                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                                    <strong class="text-truncate">{{ $r['audio_name'] }}</strong>

                                                    @if($r['is_latest'])
                                                        <span class="badge text-bg-primary">{{ __('Latest') }}</span>
                                                    @endif

                                                    <span class="badge qasr-badge-credits">
                                                        {{ strtoupper(str_replace('_', ' ', $r['model_variant'])) }}
                                                    </span>
                                                </div>

                                                <div class="qasr-transcript-meta small text-muted mt-2">
                                                    {{ $r['created_at'] }}
                                                    @if($r['duration_mins'] > 0)
                                                        | {{ __('Duration: :minutes min', ['minutes' => number_format((float) $r['duration_mins'], 2)]) }}
                                                    @endif
                                                    @if($r['word_count'] > 0)
                                                        | {{ __('Words: :count', ['count' => number_format($r['word_count'])]) }}
                                                    @endif
                                                    @if($r['char_count'] > 0)
                                                        | {{ __('Chars: :count', ['count' => number_format($r['char_count'])]) }}
                                                    @endif
                                                </div>
                                                    @if(!empty($r['audio_url']))
                                                        <div class="qasr-audio-panel mt-3" wire:ignore>
                                                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                                                <span class="small text-muted" id="qasr-time-{{ $r['id'] }}">--:-- / --:--</span>

                                                                <div class="btn-group btn-group-sm">
                                                                    <button type="button"
                                                                            class="btn btn-outline-primary btn-qasr-preview"
                                                                            data-job="{{ $r['id'] }}"
                                                                            data-url="{{ $r['audio_url'] }}"
                                                                            data-latest="{{ $r['is_latest'] ? '1' : '0' }}"
                                                                            data-preload-rank="{{ $loop->index }}">
                                                                        <i class="fa fa-play me-1"></i> {{ __('Play/Pause') }}
                                                                    </button>

                                                                    <button type="button"
                                                                            class="btn btn-outline-secondary btn-qasr-stop"
                                                                            data-job="{{ $r['id'] }}">
                                                                        <i class="fa fa-stop me-1"></i> {{ __('Stop') }}
                                                                    </button>
                                                                </div>
                                                            </div>

                                                            <div id="qasr-wrap-{{ $r['id'] }}" class="mt-1">
                                                                <div id="qasr-ph-{{ $r['id'] }}" class="border rounded bg-dark" style="height:90px; opacity:.25;"></div>
                                                                <div id="qasr-wave-{{ $r['id'] }}" class="border rounded" style="height:90px; display:none;"></div>
                                                            </div>

                                                            <a class="btn btn-sm btn-outline-success" href="{{ $r['download_url'] }}">
                                                                {{ __('Download TXT') }}
                                                            </a>
                                                            <button
                                                                class="btn btn-sm btn-outline-danger"
                                                                wire:click="deleteTranscription('{{ $r['id'] }}')"
                                                                wire:loading.attr="disabled"
                                                                wire:target="deleteTranscription('{{ $r['id'] }}')"
                                                                type="button"
                                                            >
                                                                {{ __('Delete') }}
                                                            </button>
                                                        </div>
                                                    @endif
                                                <div class="qasr-snippet-wrap mt-3">
                                                    <div class="qasr-section-caption mb-2">{{ __('Transcript Preview') }}</div>
                                                    <div class="qasr-snippet small">
                                                        {{ $r['snippet'] }}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            @endif

                            @if($this->transcriptions->hasPages())
                                <div class="mt-3">
                                    {{ $this->transcriptions->links(data: ['scrollTo' => false]) }}
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if($showEliminateModal)
        <div class="modal fade show" style="display:block;" tabindex="-1" aria-modal="true" role="dialog">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-danger">
                    <div class="modal-header">
                        <h5 class="modal-title text-danger">{{ __('Eliminate Current Job') }}</h5>
                        <button type="button" class="btn-close" wire:click="closeEliminateModal"></button>
                    </div>

                    <div class="modal-body">
                        <p class="mb-2">
                            {{ __('Are you sure you want to eliminate the current job?') }}
                        </p>

                        <div class="alert alert-warning mb-0">
                            <strong>{{ __('Warning:') }}</strong> {{ __('the credit will not be refunded and you will lose the charged credit for this job.') }}
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" wire:click="closeEliminateModal">
                            {{ __('Cancel') }}
                        </button>

                        <button type="button" class="btn btn-danger" wire:click="eliminateCurrentJob">
                            {{ __('Yes, Eliminate') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal-backdrop fade show"></div>
    @endif
</div>

@push('styles')
<link href="https://unpkg.com/filepond@^4/dist/filepond.min.css" rel="stylesheet">

<style>
    .qasr-cost-preview{
        background: rgba(var(--bs-warning-rgb), .08);
        border: 1px solid rgba(var(--bs-warning-rgb), .22);
    }

    .qasr-section-caption{
        font-size: .72rem;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: var(--bs-secondary-color);
    }

    .qasr-latest-panel{
        padding: 1rem;
        border-radius: 1rem;
        background: linear-gradient(180deg, rgba(var(--bs-primary-rgb), .08), rgba(var(--bs-info-rgb), .04));
        border: 1px solid rgba(var(--bs-primary-rgb), .16);
    }

    .qasr-output-text{
        background: rgba(var(--bs-body-color-rgb), .03);
        border: 1px solid rgba(var(--bs-body-color-rgb), .08);
        font-size: .95rem;
        line-height: 1.8;
        /* white-space: pre-wrap; */
        font-family: 'Montserrat';
        word-break: break-word;
        max-height: 380px;
        overflow-y: auto;
        direction: rtl;
        unicode-bidi: plaintext;
    }

    .qasr-empty-state{
        padding: 1.25rem;
        border-radius: 1rem;
        border: 1px dashed rgba(var(--bs-body-color-rgb), .18);
        background: rgba(var(--bs-body-color-rgb), .02);
    }

    .qasr-transcript-item{
        border-radius: 1rem;
        border: 1px solid rgba(var(--bs-body-color-rgb), .09) !important;
        background: linear-gradient(180deg, rgba(var(--bs-body-bg-rgb), .96), rgba(var(--bs-primary-rgb), .03));
        box-shadow: 0 10px 24px rgba(0, 0, 0, .04);
        transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease, background .18s ease;
    }

    .qasr-transcript-item:hover{
        transform: translateY(-2px);
        background: linear-gradient(180deg, rgba(var(--bs-body-bg-rgb), .98), rgba(var(--bs-primary-rgb), .05));
        border-color: rgba(var(--bs-primary-rgb), .22) !important;
        box-shadow: 0 14px 30px rgba(0, 0, 0, .07);
    }

    .qasr-transcript-item--latest{
        border-color: rgba(var(--bs-primary-rgb), .28) !important;
        box-shadow: inset 3px 0 0 var(--bs-primary), 0 14px 30px rgba(13, 110, 253, .08);
    }

    .qasr-transcript-meta{
        line-height: 1.75;
    }

    .qasr-audio-panel{
        padding: .9rem;
        border-radius: 1rem;
        background: rgba(var(--bs-body-color-rgb), .025);
        border: 1px solid rgba(var(--bs-body-color-rgb), .08);
    }

    .qasr-snippet-wrap{
        padding: 1rem;
        border-radius: 1rem;
        background: rgba(var(--bs-primary-rgb), .04);
        border: 1px solid rgba(var(--bs-primary-rgb), .12);
    }

    .qasr-snippet{
        line-height: 1.7;
        /* white-space: pre-line; */
        font-family: 'Montserrat';
        word-break: break-word;
        direction: rtl;
        unicode-bidi: plaintext;
    }

    .qasr-actions{
        min-width: 140px;
    }

    .qasr-actions .btn{
        width: 100%;
    }

    .qasr-badge-credits{
        background: rgba(var(--bs-warning-rgb), .18);
        color: var(--bs-warning-text-emphasis);
        border: 1px solid rgba(var(--bs-warning-rgb), .24);
    }

    .btn-xs{
        padding: .2rem .4rem;
        font-size: .72rem;
        border-radius: .3rem;
    }
</style>
@endpush

@push('scripts')
<script src="https://unpkg.com/filepond-plugin-file-validate-type/dist/filepond-plugin-file-validate-type.min.js"></script>
<script src="https://unpkg.com/filepond-plugin-file-validate-size/dist/filepond-plugin-file-validate-size.min.js"></script>
<script src="https://unpkg.com/filepond@^4/dist/filepond.min.js"></script>

<script>
(function () {
    'use strict';

    if (!window.__QASR_POND__) {
        window.__QASR_POND__ = {
            pond: null,
            listenersBound: false,
            livewireBound: false,
            commitHooked: false,
            pluginsRegistered: false,
            formWatchBoot: false,
            bootTimer: null,
        };
    }

    const S = window.__QASR_POND__;
    const FORM_KEY = 'qasr_form_state_v2';
    const FORM_TTL = 7 * 24 * 60 * 60 * 1000;

    if (!S.pluginsRegistered && window.FilePond) {
        const plugins = [];

        if (window.FilePondPluginFileValidateType) {
            plugins.push(window.FilePondPluginFileValidateType);
        }

        if (window.FilePondPluginFileValidateSize) {
            plugins.push(window.FilePondPluginFileValidateSize);
        }

        if (plugins.length > 0) {
            FilePond.registerPlugin(...plugins);
            S.pluginsRegistered = true;
        } else {
            console.warn('[QASR] FilePond plugins are not available.');
        }
    }

    function getQasrComponent() {
        if (!window.Livewire) return null;

        const root = document.getElementById('qasr-page-root');
        if (!root) return null;

        const wireId = root.getAttribute('wire:id');
        if (!wireId) return null;

        try {
            return window.Livewire.find(wireId);
        } catch (_) {
            return null;
        }
    }

    function destroyPond() {
        if (S.bootTimer) {
            clearTimeout(S.bootTimer);
            S.bootTimer = null;
        }

        if (S.pond) {
            try { S.pond.destroy(); } catch (_) {}
            S.pond = null;
        }
    }

    function safeInt(value, fallback) {
        const parsed = parseInt(value, 10);
        return Number.isFinite(parsed) ? parsed : fallback;
    }

    function formSave() {
        try {
            const lw = getQasrComponent();
            if (!lw || typeof lw.get !== 'function') return;

            localStorage.setItem(FORM_KEY, JSON.stringify({
                modelVariant: lw.get('modelVariant') ?? 'fine_tuned',
                ts: Date.now(),
            }));
        } catch (_) {}
    }

    function formLoad() {
        try {
            const raw = localStorage.getItem(FORM_KEY);
            if (!raw) return null;

            const data = JSON.parse(raw);
            if (!data || Date.now() - (data.ts || 0) > FORM_TTL) {
                localStorage.removeItem(FORM_KEY);
                return null;
            }

            return data;
        } catch (_) {
            return null;
        }
    }

    function formClear() {
        try {
            localStorage.removeItem(FORM_KEY);
        } catch (_) {}
    }

    function formRestoreIfNeeded() {
        const saved = formLoad();
        if (!saved) return;

        const lw = getQasrComponent();
        if (!lw || typeof lw.set !== 'function') return;

        try {
            lw.set('modelVariant', saved.modelVariant ?? 'fine_tuned');
        } catch (_) {}
    }

    function watchAndPersistForm() {
        if (S.formWatchBoot) return;

        const lw = getQasrComponent();
        if (!lw || typeof lw.$watch !== 'function') return;

        S.formWatchBoot = true;

        let timer = null;
        const debouncedSave = () => {
            clearTimeout(timer);
            timer = setTimeout(() => formSave(), 250);
        };

        [
            'modelVariant',
        ].forEach((field) => {
            try {
                lw.$watch(field, debouncedSave);
            } catch (_) {}
        });
    }

    function bootPond() {
        const input = document.getElementById('qasr-audio-pond');
        if (!input) return;

        destroyPond();

        const lw = getQasrComponent();
        if (!lw) return;

        S.pond = FilePond.create(input, {
            allowMultiple: false,
            allowReorder: false,
            allowReplace: true,
            credits: false,
            acceptedFileTypes: [
                'audio/wav',
                'audio/x-wav',
                'audio/mpeg',
                'audio/mp3',
                'audio/mp4',
                'audio/x-m4a',
                'audio/aac',
                'audio/ogg',
                'audio/webm',
                'audio/flac',
                'audio/x-flac'
            ],
            maxFileSize: '100MB',
            labelIdle: `
                <div class="py-3">
                    <div class="mb-1"><strong>${@js(__('Drag & Drop'))}</strong> ${@js(__('your audio file here'))}</div>
                    <div class="small text-muted">${@js(__('or'))} <span class="filepond--label-action">${@js(__('Browse'))}</span></div>
                </div>
            `,
            server: {
                process: (fieldName, file, metadata, load, error, progress, abort) => {
                    lw.upload(
                        'audioFile',
                        file,
                        () => load(file.name),
                        (e) => error(typeof e === 'string' ? e : @js(__('Upload failed'))),
                        (event) => {
                            progress(
                                event.lengthComputable,
                                event.loaded,
                                event.total
                            );
                        }
                    );

                    return {
                        abort: () => {
                            lw.removeUpload('audioFile', file.name, () => {});
                            abort();
                        }
                    };
                },
                revert: (uniqueFileId, load) => {
                    lw.call('removeAudioFile');
                    load();
                }
            }
        });
    }

    function bootQasrFilePondPage() {
        if (S.bootTimer) {
            clearTimeout(S.bootTimer);
        }

        S.bootTimer = setTimeout(() => {
            S.bootTimer = null;
            formRestoreIfNeeded();
            watchAndPersistForm();
            bootPond();
        }, 0);
    }

    if (!S.listenersBound) {
        S.listenersBound = true;

        document.addEventListener('livewire:initialized', bootQasrFilePondPage);
        document.addEventListener('livewire:navigated', bootQasrFilePondPage);
        document.addEventListener('livewire:navigating', () => {
            formSave();
            S.formWatchBoot = false;
            destroyPond();
        });
    }

    if (window.Livewire && !S.livewireBound) {
        S.livewireBound = true;

        Livewire.on('qasr-audio-file-cleared', () => {
            if (S.pond) {
                try { S.pond.removeFiles(); } catch (_) {}
            }
        });

        Livewire.on('qasr-form-reset', () => {
            if (S.pond) {
                try { S.pond.removeFiles(); } catch (_) {}
            }
            formClear();
        });

        Livewire.on('qasr-copy-text', (e) => {
            const text = e?.text || '';
            if (!text) return;
            navigator.clipboard?.writeText(text).catch(() => {});
        });

        if (!S.commitHooked && typeof Livewire.hook === 'function') {
            S.commitHooked = true;

            Livewire.hook('commit', ({ succeed }) => {
                succeed(() => {
                    requestAnimationFrame(() => {
                        formSave();
                        const input = document.getElementById('qasr-audio-pond');
                        if (input && !S.pond) {
                            bootPond();
                        }
                    });
                });
            });
        }
    }

    bootQasrFilePondPage();
})();
</script>
@endpush

@push('scripts')
<script src="https://unpkg.com/wavesurfer.js@7/dist/wavesurfer.min.js"></script>
<script>
(function () {
    'use strict';

    if (!window.__QASR_WAVE__) {
        window.__QASR_WAVE__ = {};
    }

    const S = window.__QASR_WAVE__;

    S.previewWS = S.previewWS || new Map();
    S.previewMeta = S.previewMeta || new Map();
    S.pendingFetch = S.pendingFetch || new Map();
    S.eventsBound = S.eventsBound || false;
    S.commitHooked = S.commitHooked || false;
    S.listenersBound = S.listenersBound || false;
    S.bootTimer = S.bootTimer || null;

    const CACHE_NAME = 'qasr-audio-v1';
    const CACHE_MAX = 30;
    const PRELOAD_LIMIT = 10;

    function primaryColor(isLatest = false) {
        if (isLatest) return '#dc3545';

        return (getComputedStyle(document.documentElement)
            .getPropertyValue('--bs-primary') || '#0d6efd').trim();
    }

    function buildWaveOptions(container, isLatest = false) {
        const color = primaryColor(isLatest);

        return {
            container,
            height: 90,
            normalize: true,
            responsive: true,
            backend: 'MediaElement',
            waveColor: color,
            progressColor: color,
            cursorColor: color,
        };
    }

    async function openCache() {
        return caches.open(CACHE_NAME);
    }

    async function pruneCache() {
        try {
            const cache = await openCache();
            const keys = await cache.keys();

            if (keys.length > CACHE_MAX) {
                const toDelete = keys.slice(0, keys.length - CACHE_MAX);
                await Promise.all(toDelete.map((key) => cache.delete(key)));
            }
        } catch (_) {}
    }

    async function getBlob(url) {
        if (S.pendingFetch.has(url)) {
            return S.pendingFetch.get(url);
        }

        const promise = (async () => {
            try {
                const cache = await openCache();
                const hit = await cache.match(url);
                if (hit) return hit.blob();
            } catch (_) {}

            const res = await fetch(url, {
                method: 'GET',
                cache: 'no-cache',
                credentials: 'same-origin',
            });

            if (!res.ok) throw new Error('Fetch failed ' + res.status);

            try {
                const cache = await openCache();
                await cache.put(url, res.clone());
                pruneCache().catch(() => {});
            } catch (_) {}

            return res.blob();
        })();

        S.pendingFetch.set(url, promise);
        promise.finally(() => S.pendingFetch.delete(url));

        return promise;
    }

    async function getBlobUrl(jobId, url) {
        const meta = S.previewMeta.get(jobId);
        if (meta && meta.url === url && meta.blobUrl) {
            return meta.blobUrl;
        }

        if (meta?.blobUrl) {
            try { URL.revokeObjectURL(meta.blobUrl); } catch (_) {}
        }

        const blob = await getBlob(url);
        const blobUrl = URL.createObjectURL(blob);
        S.previewMeta.set(jobId, { url, blobUrl });

        return blobUrl;
    }

    function stopWS(ws) {
        if (!ws) return;

        try {
            ws.pause();
            ws.setTime(0);
        } catch (_) {}
    }

    function stopAll(exceptJobId = null) {
        S.previewWS.forEach((ws, jobId) => {
            if (jobId !== exceptJobId) {
                stopWS(ws);
            }
        });
    }

    function destroyPreview(jobId) {
        const ws = S.previewWS.get(jobId);
        if (ws) {
            try { ws.destroy(); } catch (_) {}
            S.previewWS.delete(jobId);
        }

        const meta = S.previewMeta.get(jobId);
        if (meta?.blobUrl) {
            try { URL.revokeObjectURL(meta.blobUrl); } catch (_) {}
        }

        S.previewMeta.delete(jobId);

        const wave = document.getElementById('qasr-wave-' + jobId);
        if (wave) {
            wave.innerHTML = '';
            wave.style.display = 'none';
        }

        const ph = document.getElementById('qasr-ph-' + jobId);
        if (ph) {
            ph.style.display = '';
        }
    }

    function cleanupOrphanPreviews() {
        S.previewWS.forEach((_, jobId) => {
            if (!document.getElementById('qasr-wave-' + jobId)) {
                destroyPreview(jobId);
            }
        });
    }

    function initPreview(jobId, url, isLatest = false) {
        if (S.previewWS.has(jobId)) {
            return S.previewWS.get(jobId);
        }

        if (typeof WaveSurfer === 'undefined') {
            return null;
        }

        const ph = document.getElementById('qasr-ph-' + jobId);
        const wave = document.getElementById('qasr-wave-' + jobId);
        const time = document.getElementById('qasr-time-' + jobId);

        if (!wave || !url) return null;

        if (ph) ph.style.display = '';
        wave.style.display = 'none';

        const ws = WaveSurfer.create(buildWaveOptions(wave, isLatest));

        ws.on('ready', () => {
            if (ph) ph.style.display = 'none';
            wave.style.display = '';

            if (time) {
                time.textContent = `00:00 / ${formatTime(ws.getDuration())}`;
            }
        });

        ws.on('timeupdate', () => {
            if (time) {
                time.textContent = `${formatTime(ws.getCurrentTime())} / ${formatTime(ws.getDuration())}`;
            }
        });

        ws.on('finish', () => {
            try { ws.setTime(0); } catch (_) {}
        });

        ws.on('error', (error) => {
            console.error('[QASR] WaveSurfer error', jobId, error);
        });

        (async () => {
            try {
                const blobUrl = await getBlobUrl(jobId, url);
                ws.load(blobUrl);
            } catch (error) {
                console.warn('[QASR] Falling back to direct URL', jobId, error);
                ws.load(url);
            }
        })();

        S.previewWS.set(jobId, ws);
        return ws;
    }

    function formatTime(sec) {
        sec = Math.max(0, sec || 0);
        const m = String(Math.floor(sec / 60)).padStart(2, '0');
        const s = String(Math.floor(sec % 60)).padStart(2, '0');
        return `${m}:${s}`;
    }

    function bindPreviewButtons() {
        document.querySelectorAll('.btn-qasr-preview[data-job][data-url]').forEach((btn) => {
            if (btn.dataset.bound === '1') return;
            btn.dataset.bound = '1';

            btn.addEventListener('click', () => {
                const jobId = btn.getAttribute('data-job');
                const url = btn.getAttribute('data-url');
                const isLatest = btn.getAttribute('data-latest') === '1';

                const ws = initPreview(jobId, url, isLatest);
                if (!ws) return;

                stopAll(jobId);
                ws.playPause();
            });
        });

        document.querySelectorAll('.btn-qasr-stop[data-job]').forEach((btn) => {
            if (btn.dataset.bound === '1') return;
            btn.dataset.bound = '1';

            btn.addEventListener('click', () => {
                stopWS(S.previewWS.get(btn.getAttribute('data-job')));
            });
        });
    }

    async function preloadAndRenderRecentAudio() {
        const buttons = Array.from(
            document.querySelectorAll('.btn-qasr-preview[data-job][data-url]')
        )
            .sort((a, b) =>
                Number(a.getAttribute('data-preload-rank') ?? 9999) -
                Number(b.getAttribute('data-preload-rank') ?? 9999)
            )
            .slice(0, PRELOAD_LIMIT);

        for (const btn of buttons) {
            const jobId = btn.getAttribute('data-job');
            const url = btn.getAttribute('data-url');
            const isLatest = btn.getAttribute('data-latest') === '1';

            if (!jobId || !url) continue;
            if (S.previewWS.has(jobId)) continue;

            try {
                getBlob(url).catch(() => {});
                initPreview(jobId, url, isLatest);
            } catch (error) {
                console.error('[QASR] Preload failed', jobId, error);
            }
        }
    }

    function registerLivewireEvents() {
        if (!window.Livewire || S.eventsBound) return;
        S.eventsBound = true;

        if (!S.commitHooked && typeof Livewire.hook === 'function') {
            S.commitHooked = true;

            Livewire.hook('commit', ({ succeed }) => {
                succeed(() => {
                    requestAnimationFrame(() => {
                        bindPreviewButtons();
                        cleanupOrphanPreviews();

                    });
                });
            });
        }
    }

    function bootQasrWavePage() {
        if (S.bootTimer) {
            clearTimeout(S.bootTimer);
        }

        S.bootTimer = setTimeout(() => {
            S.bootTimer = null;

            const root = document.getElementById('qasr-page-root');
            if (!root) return;

            registerLivewireEvents();
            bindPreviewButtons();
            cleanupOrphanPreviews();

        }, 0);
    }

    function teardownQasrWavePage() {
        if (S.bootTimer) {
            clearTimeout(S.bootTimer);
            S.bootTimer = null;
        }

        S.previewWS.forEach((_, jobId) => {
            destroyPreview(jobId);
        });
    }

    if (!S.listenersBound) {
        S.listenersBound = true;

        document.addEventListener('livewire:initialized', bootQasrWavePage);
        document.addEventListener('livewire:navigated', bootQasrWavePage);
        document.addEventListener('livewire:navigating', teardownQasrWavePage);
    }

    window.addEventListener('beforeunload', teardownQasrWavePage);

    bootQasrWavePage();
})();
</script>
@endpush

