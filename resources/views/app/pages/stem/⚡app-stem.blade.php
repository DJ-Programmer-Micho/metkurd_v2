<?php

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use App\Models\MlJob;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Services\Billing\CreditService;
use App\Services\Media\AudioProbeService;
use App\Services\Providers\RunPodProvider;
use App\Services\Security\JobExecutionLockService;
use App\Services\STEM\StemJobSyncService;
use App\Services\Storage\CustomerOutputStorage;

new
#[Layout('app::layouts.app')]
class extends Component
{
    use WithPagination;
    use WithFileUploads;

    protected $paginationTheme = 'bootstrap';

    protected string $jobKind = 'stem';
    public string $toolCode = 'stem';

    #[Url(as: 'page', except: 1)]
    public int $page = 1;

    public $audioFile = null;
    public ?string $audioHash = null;
    public ?string $audioExt = null;
    public ?string $audioFileName = null;
    public ?string $audioFileMime = null;
    public ?int $audioFileBytes = null;
    public ?float $audioDurationSec = null;
    public ?float $audioDurationMin = null;
    public int $audioBillableMin = 0;

    public int $stems = 4;
    public string $model = 'htdemucs_ft';
    public string $stemCodec = 'mp3';
    public string $stemBitrate = '192k';

    public ?string $currentJobId = null;
    public ?string $providerJobId = null;
    public ?string $currentStatus = null;
    public bool $showJobStatus = false;
    public bool $jobFinished = true;
    public int $currentProgress = 0;

    public string $search = '';
    public bool $showEliminateModal = false;
    public ?string $latestFinishedJobId = null;
    public ?array $loadedRender = null;
    public int $rendersRefreshKey = 0;
    public int $walletBalance = 0;
    public int $creditsCost = 0;

    public function mount(): void
    {
        $this->syncWallet();
        $this->syncCostPreview();
        $this->hydrateCurrentJobFromDb();
        $this->syncLoadedRenderSelection();
    }

    #[On('header:refresh')]
    #[On('customerPlanUpdated')]
    #[On('customerStorageUpdated')]
    #[On('stem-renders-refresh')]
    public function refreshUi(): void
    {
        $this->syncWallet();
        $this->syncCostPreview();
        $this->hydrateCurrentJobFromDb();
        $this->syncLoadedRenderSelection();
        $this->rendersRefreshKey++;
    }

    protected function rules(): array
    {
        return [
            'audioFile' => 'required|file|mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,audio/aac,audio/ogg,audio/webm,audio/flac,audio/x-flac|max:102400',
            'stems' => 'required|integer|in:2,4',
            'model' => 'required|string|max:100',
            'stemCodec' => 'required|string|in:mp3',
            'stemBitrate' => 'required|string|in:192k',
        ];
    }

    #[Computed]
    public function recentRenders()
    {
        $this->rendersRefreshKey;

        $customerId = auth('app')->id();
        if (!$customerId) {
            return MlJob::query()->whereRaw('1 = 0')->paginate(10);
        }

        $term = trim($this->search);

        $paginator = MlJob::query()
            ->where('customer_id', $customerId)
            ->where('job_kind', $this->jobKind)
            ->where('status', 'done')
            ->when($term !== '', function ($query) use ($term) {
                $query->where(function ($nested) use ($term) {
                    $nested->where('id', 'like', '%' . $term . '%')
                        ->orWhere('input->audio_name', 'like', '%' . $term . '%');
                });
            })
            ->orderByDesc('finished_at')
            ->paginate(10);

        $paginator->setCollection(
            $paginator->getCollection()->values()->map(function (MlJob $job, int $index) {
                return array_merge(
                    $this->buildRenderPayload($job),
                    ['is_latest' => $index === 0]
                );
            })
        );

        return $paginator;
    }

    protected function currentFolderForCustomer($customer): string
    {
        return \App\Support\CustomerFolder::make(
            (int) $customer->id,
            $customer->profile?->first_name ?? $customer->first_name ?? null,
            $customer->profile?->last_name ?? $customer->last_name ?? null,
            $customer->username ?? null
        );
    }

    protected function resetAudioState(bool $dispatchBrowserEvent = true): void
    {
        $this->audioFile = null;
        $this->audioHash = null;
        $this->audioExt = null;
        $this->audioFileName = null;
        $this->audioFileMime = null;
        $this->audioFileBytes = null;
        $this->audioDurationSec = null;
        $this->audioDurationMin = null;
        $this->audioBillableMin = 0;
        $this->creditsCost = 0;

        if ($dispatchBrowserEvent) {
            $this->dispatch('stem-audio-file-cleared');
        }
    }

    protected function resetJobState(): void
    {
        $this->currentJobId = null;
        $this->providerJobId = null;
        $this->currentStatus = null;
        $this->showJobStatus = false;
        $this->jobFinished = true;
        $this->currentProgress = 0;
    }

    protected function resolveProgressForStatus(?string $status): int
    {
        return match ((string) $status) {
            'queued' => 10,
            'running' => 65,
            'saving' => 90,
            'done', 'failed', 'deleted', 'delete_failed' => 100,
            default => 0,
        };
    }

    protected function hydrateCurrentJobFromDb(): void
    {
        $customerId = auth('app')->id();
        if (!$customerId) {
            $this->resetJobState();
            return;
        }

        $job = MlJob::query()
            ->where('customer_id', $customerId)
            ->where('job_kind', $this->jobKind)
            ->whereIn('status', ['queued', 'running', 'saving'])
            ->orderByDesc('updated_at')
            ->first();

        if (!$job) {
            $this->resetJobState();
            return;
        }

        $this->applyJobStateFromModel($job);
    }

    protected function latestFinishedStemJob(): ?MlJob
    {
        $customerId = auth('app')->id();
        if (!$customerId) {
            return null;
        }

        return MlJob::query()
            ->where('customer_id', $customerId)
            ->where('job_kind', $this->jobKind)
            ->where('status', 'done')
            ->orderByDesc('finished_at')
            ->first();
    }

    protected function finishedStemJobById(string $jobId): ?MlJob
    {
        $customerId = auth('app')->id();
        if (!$customerId || $jobId === '') {
            return null;
        }

        return MlJob::query()
            ->where('id', $jobId)
            ->where('customer_id', $customerId)
            ->where('job_kind', $this->jobKind)
            ->where('status', 'done')
            ->first();
    }

    protected function syncLoadedRenderSelection(bool $dispatchBrowserEvent = false): void
    {
        $customerId = auth('app')->id();
        if (!$customerId) {
            $this->latestFinishedJobId = null;
            $this->loadedRender = null;

            if ($dispatchBrowserEvent) {
                $this->dispatch('stem-render-cleared');
            }

            return;
        }

        $latestJob = $this->latestFinishedStemJob();
        $this->latestFinishedJobId = $latestJob ? (string) $latestJob->id : null;

        $selectedId = (string) ($this->loadedRender['id'] ?? '');
        $selectedJob = $selectedId !== ''
            ? $this->finishedStemJobById($selectedId)
            : null;

        if ($selectedJob) {
            $this->setLoadedRenderFromJob($selectedJob, false);
            return;
        }

        if ($latestJob) {
            $this->setLoadedRenderFromJob($latestJob, $dispatchBrowserEvent);
            return;
        }

        $this->loadedRender = null;

        if ($dispatchBrowserEvent) {
            $this->dispatch('stem-render-cleared');
        }
    }

    protected function applyJobStateFromModel(MlJob $job): void
    {
        $status = (string) $job->status;

        $this->currentJobId = (string) $job->id;
        $this->providerJobId = (string) ($job->provider_job_id ?? '');
        $this->currentStatus = $status;
        $this->jobFinished = in_array($status, ['done', 'failed', 'deleted', 'delete_failed'], true);
        $this->showJobStatus = !$this->jobFinished;
        $this->currentProgress = $this->resolveProgressForStatus($status);
    }

    public function updatedAudioFile(AudioProbeService $probe): void
    {
        $this->validateOnly('audioFile');

        if (!$this->audioFile) {
            return;
        }

        try {
            $info = $probe->probeUploadedFile($this->audioFile);

            $this->audioFileName = (string) $this->audioFile->getClientOriginalName();
            $this->audioFileBytes = (int) $this->audioFile->getSize();
            $this->audioFileMime = (string) ($this->audioFile->getMimeType() ?: 'audio/*');
            $this->audioDurationSec = (float) $info['duration_sec'];
            $this->audioDurationMin = (float) $info['duration_min'];
            $this->audioBillableMin = (int) $info['billable_min'];
            $this->audioExt = (string) $info['audio_ext'];

            $realPath = $this->audioFile->getRealPath();
            $this->audioHash = $realPath && is_file($realPath)
                ? hash_file('sha256', $realPath)
                : sha1(($this->audioFileName ?? '') . '|' . ($this->audioFileBytes ?? 0));

            $this->syncCostPreview();
            $this->dispatch('alert', type: 'success', message: __('Audio uploaded successfully.'));
        } catch (\Throwable $e) {
            Log::error('STEM_AUDIO_UPLOAD_FAIL', [
                'message' => $e->getMessage(),
            ]);

            $this->resetAudioState();
            $this->dispatch('alert', type: 'error', message: __('Failed to inspect the uploaded audio file.'));
        }
    }

    public function removeAudioFile(): void
    {
        $this->resetAudioState();
    }

    public function updatedStems(): void
    {
        $this->syncCostPreview();
    }

    public function updatedModel(): void
    {
        $this->syncCostPreview();
    }

    public function updatedStemCodec(): void
    {
        $this->syncCostPreview();
    }

    public function updatedStemBitrate(): void
    {
        $this->syncCostPreview();
    }

    protected function actionCode(): string
    {
        return $this->stems === 2 ? 'sep2' : 'sep4';
    }

    protected function fullActionCode(): string
    {
        return $this->toolCode . '.' . $this->actionCode();
    }

    protected function requiredCredits(): int
    {
        $customer = auth('app')->user();
        $outputs = $this->stems === 2 ? 2 : 4;

        if ($customer && method_exists($customer, 'priceCreditsFor')) {
            $credits = (int) $customer->priceCreditsFor($this->fullActionCode(), [
                'outputs' => $outputs,
                'stem_outputs' => $outputs,
                'separation_mode' => $this->stems,
            ]);

            if ($credits > 0) {
                return $credits;
            }
        }

        return $outputs * 500;
    }

    protected function syncWallet(): void
    {
        $customer = auth('app')->user();
        $wallet = $customer?->wallet()->first();

        $subscription = (int) ($wallet?->subscription_balance_credits ?? 0);
        $addon = (int) ($wallet?->addon_balance_credits ?? 0);

        $this->walletBalance = $subscription + $addon;
    }

    protected function syncCostPreview(): void
    {
        $customer = auth('app')->user();

        if (!$customer || !$this->audioFile || $this->audioDurationSec === null || $this->audioDurationSec <= 0) {
            $this->creditsCost = 0;
            return;
        }

        $this->creditsCost = max(0, $this->requiredCredits());
    }

    #[Computed]
    public function canSeparate(): bool
    {
        return $this->separateBlockedReason === null;
    }

    #[Computed]
    public function separateBlockedReason(): ?string
    {
        $customer = auth('app')->user();

        if (!$customer) {
            return __('You must be logged in.');
        }

        if ($this->currentJobId && !$this->jobFinished) {
            return __('A stem separation job is already in progress.');
        }

        if (method_exists($customer, 'isAllowed') && !$customer->isAllowed($this->fullActionCode())) {
            return __('Your plan does not allow this STEM separation mode.');
        }

        if (!$this->audioFile) {
            return __('Please upload an audio file.');
        }

        if ($this->audioDurationSec === null || $this->audioDurationSec <= 0) {
            return __('Audio duration could not be detected.');
        }

        if ($this->creditsCost <= 0) {
            return __('Pricing could not be calculated.');
        }

        if ($this->walletBalance < $this->creditsCost) {
            return __('Not enough credits.');
        }

        return null;
    }

    protected function availableTracks(int $mode): array
    {
        return $mode === 2
            ? ['original', 'vocals', 'instrumental']
            : ['original', 'vocals', 'drums', 'bass', 'other'];
    }

    protected function buildRenderPayload(MlJob $job): array
    {
        $locale = app()->getLocale();
        $mode = (int) (data_get($job->meta, 'separation_mode') ?: data_get($job->input, 'stems', 4));
        $tracks = $this->availableTracks($mode);

        $streams = [];
        $downloads = [];

        foreach ($tracks as $track) {
            $streams[$track] = route('app.renders.stem.stream', [
                'locale' => $locale,
                'jobId' => (string) $job->id,
                'track' => $track,
            ]) . '?proxy=1';

            $downloads[$track] = route('app.renders.stem.download', [
                'locale' => $locale,
                'jobId' => (string) $job->id,
                'track' => $track,
            ]);
        }

        $downloads['all'] = route('app.renders.stem.zip', [
            'locale' => $locale,
            'jobId' => (string) $job->id,
        ]);

        return [
            'id' => (string) $job->id,
            'mode' => $mode,
            'tracks' => $tracks,
            'stems' => $streams,
            'downloads' => $downloads,
            'meta' => (array) ($job->meta ?? []),
            'input_name' => (string) data_get($job->input, 'audio_name', __('Untitled audio')),
            'created_at' => optional($job->finished_at ?? $job->created_at)->format('Y-m-d H:i'),
            'created_at_human' => optional($job->finished_at ?? $job->created_at)->diffForHumans(),
        ];
    }

    protected function setLoadedRenderFromJob(MlJob $job, bool $dispatchBrowserEvent = true): array
    {
        $render = $this->buildRenderPayload($job);
        $this->loadedRender = $render;
        $this->latestFinishedJobId = (string) $job->id;

        if ($dispatchBrowserEvent) {
            $this->dispatch('stem-render-loaded', render: $render);
        }

        return $render;
    }

    public function submit(
        RunPodProvider $runpod,
        CreditService $credits,
        StemJobSyncService $sync,
        JobExecutionLockService $locks,
        CustomerOutputStorage $storage
    ): void {
        $this->hydrateCurrentJobFromDb();

        if ($this->currentJobId && !$this->jobFinished) {
            $this->dispatch('alert', type: 'warning', message: __('A stem separation job is already in progress.'));
            return;
        }

        $customer = auth('app')->user();
        if (!$customer) {
            $this->dispatch('alert', type: 'error', message: __('You must be logged in.'));
            return;
        }

        if (method_exists($customer, 'isAllowed') && !$customer->isAllowed($this->fullActionCode())) {
            $this->dispatch('alert', type: 'error', message: __('Your plan does not allow this STEM separation mode.'));
            return;
        }

        $this->validate();

        if ($this->audioDurationSec === null || $this->audioDurationSec <= 0) {
            $this->dispatch('alert', type: 'error', message: __('Audio duration could not be detected.'));
            return;
        }

        $needed = $this->requiredCredits();
        if ($needed <= 0) {
            $this->dispatch('alert', type: 'error', message: __('Pricing is not configured for STEM separation.'));
            return;
        }

        $jobId = (string) Str::uuid();
        $savedAudio = null;
        $charged = false;
        $refundReason = 'provider_start_failed';

        try {
            try {
                $credits->charge((int) $customer->id, $needed, 'stem_charge', [
                    'related_type' => 'ml_job',
                    'related_id' => $jobId,
                    'tool_action' => $this->fullActionCode(),
                    'outputs' => (int) $this->stems,
                    'stem_outputs' => (int) $this->stems,
                    'separation_mode' => (int) $this->stems,
                    'seconds' => (float) $this->audioDurationSec,
                    'minutes' => (int) $this->audioBillableMin,
                ]);
                $charged = true;
            } catch (\Throwable) {
                $this->syncWallet();
                $this->dispatch('alert', type: 'error', message: __('Not enough credits.'));
                return;
            }

            $folder = $this->currentFolderForCustomer($customer->loadMissing('profile'));
            $audioExt = strtolower((string) ($this->audioExt ?: $this->audioFile?->getClientOriginalExtension() ?: 'wav'));
            $audioKey = "renders/{$folder}/stem/{$jobId}/input.{$audioExt}";
            $toolId = Tool::query()->where('code', $this->toolCode)->value('id');
            $actionId = ToolAction::query()
                ->where('tool_code', $this->toolCode)
                ->where('action_code', $this->actionCode())
                ->value('id');

            DB::transaction(function () use ($jobId, $customer, $needed, $toolId, $actionId) {
                MlJob::create([
                    'id' => $jobId,
                    'customer_id' => (int) $customer->id,
                    'tool_id' => $toolId,
                    'tool_action_id' => $actionId,
                    'job_kind' => $this->jobKind,
                    'status' => 'queued',
                    'provider' => 'runpod',
                    'provider_job_id' => null,
                    'input_hash' => (string) $this->audioHash,
                    'credits_charged' => (int) $needed,
                    'input' => [
                        'separation_mode' => (int) $this->stems,
                        'stems' => (int) $this->stems,
                        'model' => (string) $this->model,
                        'stem_codec' => (string) $this->stemCodec,
                        'stem_bitrate' => (string) $this->stemBitrate,
                        'audio_name' => (string) $this->audioFileName,
                        'audio_mime' => (string) $this->audioFileMime,
                        'audio_bytes' => (int) $this->audioFileBytes,
                        'audio_duration_sec' => (float) $this->audioDurationSec,
                        'audio_duration_min' => (float) $this->audioDurationMin,
                        'audio_billable_min' => (int) $this->audioBillableMin,
                    ],
                    'output' => null,
                    'error' => null,
                    'started_at' => now(),
                    'finished_at' => null,
                    'storage_in_bytes' => 0,
                    'storage_out_bytes' => 0,
                    'meta' => [
                        'billing_metric' => 'stem_output',
                        'billing_action' => $this->actionCode(),
                        'separation_mode' => (int) $this->stems,
                        'duration_seconds' => (float) $this->audioDurationSec,
                        'model' => (string) $this->model,
                        'stem_codec' => (string) $this->stemCodec,
                        'stem_bitrate' => (string) $this->stemBitrate,
                    ],
                ]);
            }, 3);

            $savedAudio = $storage->saveUploadedFileToS3(
                (int) $customer->id,
                $this->audioFile,
                $audioKey,
                [
                    'job_id' => $jobId,
                    'tool' => 'stem',
                    'purpose' => 'input_audio',
                    'role' => 'source_audio',
                    'checksum' => $this->audioHash,
                    'original_name' => $this->audioFileName,
                ]
            );

            $audioUrl = $storage->temporaryUrl($savedAudio['path'], 120, [
                'ResponseContentType' => $savedAudio['mime'] ?? $this->audioFileMime,
            ]);

            $job = MlJob::query()->findOrFail($jobId);
            $job->update([
                'input' => array_merge((array) ($job->input ?? []), [
                    'audio_disk' => (string) $savedAudio['disk'],
                    'audio_path' => (string) $savedAudio['path'],
                    'audio_url' => (string) $audioUrl,
                    'audio_ext' => (string) $audioExt,
                ]),
                'storage_in_bytes' => (int) $savedAudio['bytes'],
            ]);

            $lock = $locks->acquireStemLock(
                customerId: (int) $customer->id,
                jobId: $jobId,
                inputHash: (string) $this->audioHash,
                session: request()->session(),
                agent: request()->userAgent(),
                ip: request()->ip()
            );

            if (!($lock['ok'] ?? false)) {
                $refundReason = 'stem_lock_conflict';
                throw new \RuntimeException((string) ($lock['message'] ?? __('Could not lock the STEM job.')));
            }

            $payload = $sync->buildRunpodInput(
                job: $job->fresh(),
                inputDisk: (string) $savedAudio['disk'],
                inputPath: (string) $savedAudio['path'],
                stems: (int) $this->stems,
                model: (string) $this->model,
                stemCodec: (string) $this->stemCodec,
                stemBitrate: (string) $this->stemBitrate
            );

            $endpointId = (string) (config('runpod.endpoints.stem') ?: env('RUNPOD_ENDPOINT_ID_STEM'));
            if ($endpointId === '') {
                throw new \RuntimeException(__('RUNPOD_ENDPOINT_ID_STEM is missing.'));
            }

            $response = $runpod->run($endpointId, $payload);

            $providerJobId = (string) data_get($response, 'id', '');
            if ($providerJobId === '') {
                throw new \RuntimeException('RunPod did not return a provider job ID.');
            }

            $job->update([
                'provider_job_id' => $providerJobId,
                'status' => 'running',
            ]);

            $this->currentJobId = $jobId;
            $this->providerJobId = $providerJobId;
            $this->currentStatus = 'running';
            $this->showJobStatus = true;
            $this->jobFinished = false;
            $this->currentProgress = 15;

            $this->dispatch('header:refresh');
            $this->dispatch('customerPlanUpdated');
            $this->dispatch('customerStorageUpdated');
            $this->dispatch('stem-renders-refresh');
            $this->dispatch('stem-job-started', [
                'jobId' => $jobId,
                'providerJobId' => $providerJobId,
                'status' => 'running',
                'progress' => 15,
            ]);
            $this->dispatch('alert', type: 'success', message: __('Stem separation job submitted.'));
            $this->syncWallet();
        } catch (\Throwable $e) {
            Log::error('STEM_SUBMIT_FAIL', [
                'job_id' => $jobId,
                'message' => $e->getMessage(),
            ]);

            if ($savedAudio && !empty($savedAudio['path'])) {
                try {
                    $storage->deleteFromS3AndUncount(
                        (int) $customer->id,
                        (string) $savedAudio['path'],
                        (int) ($savedAudio['bytes'] ?? 0)
                    );
                } catch (\Throwable $cleanup) {
                    Log::warning('STEM_SUBMIT_CLEANUP_FAIL', [
                        'job_id' => $jobId,
                        'message' => $cleanup->getMessage(),
                    ]);
                }
            }

            if ($charged) {
                try {
                    $credits->refund((int) $customer->id, $needed, 'stem_refund', [
                        'related_type' => 'ml_job',
                        'related_id' => $jobId,
                        'tool_action' => $this->fullActionCode(),
                        'reason' => $refundReason,
                    ]);
                } catch (\Throwable $refundError) {
                    Log::warning('STEM_REFUND_FAIL', [
                        'job_id' => $jobId,
                        'message' => $refundError->getMessage(),
                    ]);
                }
            }

            try {
                $locks->releaseLock($jobId);
            } catch (\Throwable) {
            }

            MlJob::query()
                ->where('id', $jobId)
                ->update([
                    'status' => 'failed',
                    'error' => ['message' => $e->getMessage()],
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->dispatch('stem-job-state-clear');
            $this->dispatch('customerPlanUpdated');
            $this->dispatch('alert', type: 'error', message: $e->getMessage());
            $this->syncWallet();
            $this->resetJobState();
        }
    }

    public function pollJob(StemJobSyncService $sync): void
    {
        if (!$this->currentJobId) {
            $this->hydrateCurrentJobFromDb();
            return;
        }

        $job = MlJob::query()
            ->where('id', $this->currentJobId)
            ->where('customer_id', auth('app')->id())
            ->first();

        if (!$job) {
            $this->hydrateCurrentJobFromDb();
            return;
        }

        $result = $sync->sync($job);

        $this->currentStatus = (string) ($result['status'] ?? $job->status);
        $this->currentProgress = (int) ($result['progress'] ?? $this->resolveProgressForStatus($this->currentStatus));
        $this->showJobStatus = true;
        $this->jobFinished = false;

        $this->dispatch('stem-job-state-sync', [
            'jobId' => (string) $job->id,
            'status' => $this->currentStatus,
            'progress' => $this->currentProgress,
        ]);

        if (!empty($result['done'])) {
            $fresh = MlJob::query()
                ->where('id', $job->id)
                ->where('customer_id', auth('app')->id())
                ->first();

            if ($fresh) {
                $render = $this->setLoadedRenderFromJob($fresh);
                $this->dispatch('stem-job-completed', render: $render);
            } else {
                $this->dispatch('stem-job-completed');
            }

            $this->dispatch('stem-job-state-clear');
            $this->dispatch('header:refresh');
            $this->dispatch('customerStorageUpdated');
            $this->dispatch('stem-renders-refresh');
            $this->dispatch('alert', type: 'success', message: (string) ($result['message'] ?? __('Stem separation completed.')));
            $this->resetJobState();
            $this->syncLoadedRenderSelection();

            return;
        }

        if (!empty($result['failed'])) {
            $this->dispatch('stem-job-state-clear');
            $this->dispatch('header:refresh');
            $this->dispatch('stem-renders-refresh');
            $this->dispatch('alert', type: 'error', message: (string) ($result['message'] ?? __('Stem separation failed.')));
            $this->resetJobState();
        }
    }

    public function loadRender(string $jobId): void
    {
        $job = MlJob::query()
            ->where('id', $jobId)
            ->where('customer_id', auth('app')->id())
            ->where('job_kind', $this->jobKind)
            ->where('status', 'done')
            ->first();

        if (!$job) {
            $this->dispatch('alert', type: 'error', message: __('Render not found.'));
            return;
        }

        $this->setLoadedRenderFromJob($job);
    }

    public function deleteRender(string $jobId, CustomerOutputStorage $storage): void
    {
        $job = MlJob::query()
            ->where('id', $jobId)
            ->where('customer_id', auth('app')->id())
            ->where('job_kind', $this->jobKind)
            ->where('status', 'done')
            ->first();

        if (!$job) {
            $this->dispatch('alert', type: 'error', message: __('Render not found.'));
            return;
        }

        try {
            $storage->deleteStemOutputs($job);

            $deletedLoadedRender = (($this->loadedRender['id'] ?? null) === (string) $job->id);
            if ($deletedLoadedRender) {
                $this->loadedRender = null;
            }

            $this->resetPage();
            $this->syncLoadedRenderSelection($deletedLoadedRender);
            $this->dispatch('customerStorageUpdated');
            $this->dispatch('header:refresh');
            $this->dispatch('stem-renders-refresh');
            $this->dispatch('alert', type: 'success', message: __('Render deleted.'));
        } catch (\Throwable $e) {
            Log::error('STEM_DELETE_RENDER_FAIL', [
                'job_id' => (string) $job->id,
                'message' => $e->getMessage(),
            ]);

            $this->dispatch('alert', type: 'error', message: __('Failed to delete render.'));
        }
    }

    public function resetForm(): void
    {
        $this->resetAudioState();

        $this->stems = 4;
        $this->model = 'htdemucs_ft';
        $this->stemCodec = 'mp3';
        $this->stemBitrate = '192k';
        $this->syncCostPreview();

        $this->dispatch('stem-form-reset');
        $this->dispatch('stem-form-state-clear');
    }

    public function render()
    {
        return view('app.pages.stem.⚡app-stem');
    }

    public function openEliminateModal(): void
    {
        $this->showEliminateModal = true;
    }

    public function closeEliminateModal(): void
    {
        $this->showEliminateModal = false;
    }

    public function eliminateCurrentJob(
        JobExecutionLockService $locks,
        CustomerOutputStorage $storage
    ): void {
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
                    'message' => 'Eliminated by customer. Credits are not refundable.',
                    'type' => 'eliminated_by_customer',
                ],
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            $audioPath  = (string) data_get($job->input, 'audio_path', '');
            $audioBytes = (int) ((int) $job->storage_in_bytes ?: data_get($job->input, 'audio_bytes', 0));

            try {
                if ($audioPath !== '') {
                    $storage->deleteFromS3AndUncount((int) $customerId, $audioPath, $audioBytes);
                }
            } catch (\Throwable $e) {
                Log::warning('STEM_ELIMINATE_AUDIO_DELETE_FAIL', [
                    'job_id' => (string) $job->id,
                    'path' => $audioPath,
                    'message' => $e->getMessage(),
                ]);
            }

            $locks->releaseLock((string) $job->id);
        }

        $this->dispatch('stem-job-state-clear');
        $this->dispatch('header:refresh');
        $this->dispatch('customerStorageUpdated');
        $this->dispatch('stem-renders-refresh');
        $this->dispatch('alert', type: 'warning', message: __('Current STEM job eliminated. Credits were not refunded.'));
        $this->resetJobState();
    }
};
?>

<x-slot:title>{{ __('Stem Separation') }} | {{ __('MET KURD') }}</x-slot:title>

<div id="stem-page-root">
    @if($currentJobId && !$jobFinished)
        <div wire:poll.5000ms="pollJob"></div>
    @endif

    @php
        $loadedTracks = $loadedRender['tracks'] ?? [];

        $stemColors = [
            'original' => '#95a5a6',
            'vocals' => '#e74c3c',
            'instrumental' => '#3498db',
            'drums' => '#f1c40f',
            'bass' => '#9b59b6',
            'other' => '#1abc9c',
        ];

        $trackLabel = function ($track) {
            return match ($track) {
                'original' => __('Original'),
                'vocals' => __('Vocals'),
                'instrumental' => __('Instrumental'),
                'drums' => __('Drums'),
                'bass' => __('Bass'),
                'other' => __('Other'),
                default => __(\Illuminate\Support\Str::headline((string) $track)),
            };
        };

        $latestTitle = $loadedRender['input_name'] ?? null;
        $latestTitle = $latestTitle ?: ($loadedRender['id'] ?? '-');

        $status = $currentStatus ?? 'queued';
        $statusLabel = match ($status) {
            'queued' => __('Queued'),
            'running' => __('Running'),
            'saving' => __('Saving'),
            'done' => __('Done'),
            'failed' => __('Failed'),
            default => __('Queued'),
        };
        $badge = match($status) {
            'queued' => 'secondary',
            'running' => 'info',
            'saving' => 'warning',
            'done' => 'success',
            'failed' => 'danger',
            default => 'secondary'
        };

        $glassClass = match($status) {
            'running' => 'glass-load--info',
            'saving' => 'glass-load--warning',
            'done' => 'glass-load--success',
            'failed' => 'glass-load--danger',
            default => 'glass-load--secondary'
        };
    @endphp

    <div class="row">
        <div class="col-lg-8 mb-4">

            @if($showJobStatus && $currentJobId)
                <div class="card glass-load {{ $glassClass }} mb-3">
                    <div class="card-body py-2 px-3">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                            <div class="d-flex align-items-center">
                                <span class="badge badge-{{ $badge }} mr-2 text-uppercase" style="letter-spacing:.5px;">
                                    {{ $currentStatus }}
                                </span>
                                <small class="tts-status-muted">
                                    {{ __('Job ID:') }} <span class="font-weight-bold">{{ $currentJobId }}</span>
                                </small>
                            </div>

                            <div>
                                @if(!$jobFinished)
                                    <small class="tts-status-muted">
                                        <span class="spinner-border spinner-border-sm mr-1" role="status"></span>
                                        {{ __('Working...') }}
                                    </small>
                                @else
                                    <small class="tts-status-muted">{{ __('Finished') }}</small>
                                @endif
                            </div>
                        </div>

                        <div class="progress stem-job-progress">
                            <div
                                class="progress-bar progress-bar-striped progress-bar-animated"
                                role="progressbar"
                                style="width: {{ max(5, min(100, $currentProgress)) }}%;"
                                aria-valuenow="{{ $currentProgress }}"
                                aria-valuemin="0"
                                aria-valuemax="100"
                            >
                                {{ $currentProgress }}%
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <div class="turbo-border mb-3">
                <div class="turbo-inner">
                    <div class="card mb-0">
                        <div class="card-body p-3 p-md-4">
                            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                <div>
                                    <strong class="d-block">{{ __('Stem Separation') }}</strong>
                                    <small class="text-muted">{{ __('Separate music stems') }}</small>
                                </div>
                                <span class="badge badge-primary">{{ __('Beta') }}</span>
                            </div>

                            <div class="d-flex flex-wrap gap-3 mb-3 stem-top-mini-stats">
                                <div class="stem-mini-stat">
                                    <div class="text-muted small">{{ __('Wallet') }}</div>
                                    <div class="fw-semibold">{{ number_format($walletBalance) }}</div>
                                </div>
                                <div class="stem-mini-stat">
                                    <div class="text-muted small">{{ __('Cost') }}</div>
                                    <div class="fw-semibold">{{ number_format($creditsCost) }}</div>
                                </div>
                                @if($audioDurationMin)
                                    <div class="stem-mini-stat">
                                        <div class="text-muted small">{{ __('Minutes') }}</div>
                                        <div class="fw-semibold">{{ number_format((float) $audioDurationMin, 2) }}</div>
                                    </div>
                                @endif
                            </div>

                            <div class="stem-plugin-card mb-3">
                                <label class="mb-1 font-weight-medium">{{ __('Audio File') }}</label>
                                <div wire:ignore>
                                    <input type="file" id="stem-audio-pond">
                                </div>

                                <div wire:loading wire:target="audioFile" class="small text-primary mt-2">
                                    {{ __('Uploading audio...') }}
                                </div>

                                <small class="text-muted d-block mt-2">
                                    {{ __('WAV recommended | Max 100MB') }}
                                </small>
                            </div>

                            @if($audioFileName)
                                <div class="stem-upload-meta mb-3">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                        <div>
                                            <div class="fw-semibold">{{ $audioFileName }}</div>
                                            <div class="small text-muted">
                                                {{ $audioDurationSec ? number_format($audioDurationSec, 2) . ' ' . __('sec') : __('Unknown duration') }}
                                                @if($audioFileBytes)
                                                    | {{ number_format($audioFileBytes / 1024 / 1024, 2) }} MB
                                                @endif
                                            </div>
                                        </div>

                                        <button class="btn btn-outline-danger btn-sm" wire:click="removeAudioFile" type="button">
                                            {{ __('Remove') }}
                                        </button>
                                    </div>
                                </div>
                            @endif

                            <div class="d-flex flex-wrap align-items-center justify-content-between mt-3 gap-2">
                                <div class="d-flex flex-wrap gap-2">
                                    <button
                                        class="btn {{ $this->canSeparate ? 'btn-success' : 'btn-danger' }}"
                                        wire:click="submit"
                                        wire:loading.attr="disabled"
                                        wire:target="submit,audioFile"
                                        @disabled(!$this->canSeparate)
                                        type="button"
                                    >
                                        <span wire:loading.remove wire:target="submit,audioFile">
                                            <span class="mr-1" aria-hidden="true">▶</span>
                                            {{ $this->canSeparate ? __('Separate') : ($this->separateBlockedReason ?? __('Separate')) }}
                                        </span>

                                        <span wire:loading wire:target="audioFile">
                                            <span class="spinner-border spinner-border-sm mr-1"></span>
                                            {{ __('Uploading audio...') }}
                                        </span>

                                        <span wire:loading wire:target="submit">
                                            <span class="spinner-border spinner-border-sm mr-1"></span>
                                            {{ __('Sending...') }}
                                        </span>
                                    </button>

                                    <button class="btn btn-outline-secondary" wire:click="resetForm" type="button">
                                        {{ __('Clear') }}
                                    </button>
                                </div>

                                @if($currentJobId && !$jobFinished)
                                    <button type="button" class="btn btn-outline-danger" wire:click="openEliminateModal">
                                        {{ __('Eliminate Current Job') }}
                                    </button>
                                @endif
                            </div>

                            @if($walletBalance < $creditsCost && $creditsCost > 0)
                                <small class="text-danger d-block mt-2">
                                    {{ __('Not enough credits for this separation.') }}
                                </small>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="turbo-border mb-3">
                <div class="turbo-inner">
                    <div class="card mb-0">
                        <div class="card-body p-3 p-md-4">
                            <div class="d-flex align-items-center justify-content-between flex-wrap mb-3">
                                <div>
                                    <strong class="d-block">{{ __('Latest Output') }}</strong>
                                    <small class="text-muted">{{ __('Title:') }} <b id="stem-latest-title">{{ $latestTitle }}</b></small>
                                </div>

                                <div class="d-flex align-items-center gap-2">
                                    @if($loadedRender && isset($loadedRender['downloads']['all']))
                                        <small id="stem-current-render-label" class="text-muted mt-2 mt-md-0 mr-2">
                                            {{ __('Render:') }} <b id="stem-current-render-id">#{{ $loadedRender['id'] }}</b>
                                        </small>
                                        <a
                                            id="stem-download-all"
                                            href="{{ $loadedRender['downloads']['all'] ?? '#' }}"
                                            class="btn btn-sm btn-primary {{ isset($loadedRender['downloads']['all']) ? '' : 'disabled' }}"
                                            title="{{ __('Download All as ZIP') }}"
                                        >
                                            <i class="mdi mdi-download"></i> {{ __('Download ZIP') }}
                                        </a>
                                    @else
                                        <small id="stem-current-render-label" class="text-muted mt-2 mt-md-0 mr-2 d-none">
                                            {{ __('Render:') }} <b id="stem-current-render-id"></b>
                                        </small>
                                        <a
                                            id="stem-download-all"
                                            href="#"
                                            class="btn btn-sm btn-primary disabled"
                                            title="{{ __('Download All as ZIP') }}"
                                        >
                                            <i class="mdi mdi-download"></i> {{ __('Download ZIP') }}
                                        </a>
                                    @endif
                                </div>
                            </div>

                            <input type="hidden" id="stem-latest-ready" value="{{ $loadedRender ? 1 : 0 }}">
                            <input type="hidden" id="stem-latest-render-id" value="{{ $loadedRender['id'] ?? '' }}">
                            <script type="application/json" id="stem-initial-render-data">{!! json_encode($loadedRender, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>

                            <div id="stem-empty-state" class="{{ $loadedRender ? 'd-none' : '' }}">
                                <div class="text-muted small text-center py-4">{{ __('No output selected yet.') }}</div>
                            </div>

                            <div id="stem-tracks-wrapper" wire:ignore class="{{ $loadedRender ? '' : 'd-none' }}">
                                <hr class="mt-3">

                                <div class="stem-player-container">
                                    <div class="master-controls mb-3">
                                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                            <div class="d-flex align-items-center gap-2">
                                                <button id="stem-master-play" type="button" class="btn btn-lg btn-success btn-master-play" {{ $loadedRender ? '' : 'disabled' }}>
                                                    <i class="mdi mdi-play"></i> {{ __('Play All') }}
                                                </button>
                                                <button id="stem-master-stop" type="button" class="btn btn-lg btn-outline-secondary btn-master-stop" {{ $loadedRender ? '' : 'disabled' }}>
                                                    <i class="mdi mdi-stop"></i> {{ __('Stop') }}
                                                </button>
                                            </div>
                                            <div class="master-time text-muted">
                                                <span id="master-current">00:00</span> / <span id="master-duration">00:00</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div id="stem-tracks" class="d-flex flex-column gap-3">
                                        @foreach($loadedTracks as $track)
                                            @php
                                                $color = $stemColors[$track] ?? '#0d6efd';
                                                $isMutedByDefault = $track === 'original';
                                            @endphp

                                            <div class="stem-track glass-load-stem mb-3 stem-track-row" data-track="{{ $track }}" data-stem-key="render-{{ $track }}" style="--stem-color: {{ $color }};">
                                                <div class="stem-track-header">
                                                    <div class="stem-track-label">
                                                        <span class="stem-badge">{{ $trackLabel($track) }}</span>
                                                        <div class="small text-muted mt-1">
                                                            {{ $track === 'original' ? __('Original uploaded audio - muted by default') : __('Separated output track') }}
                                                        </div>
                                                    </div>

                                                    <div class="stem-track-controls d-flex gap-2 flex-wrap">
                                                        <button type="button" class="btn btn-sm btn-stem-play track-play btn-outline-success" data-track="{{ $track }}">
                                                            <i class="mdi mdi-play"></i> {{ __('Play') }}
                                                        </button>

                                                        <button type="button" class="btn btn-sm btn-stem-solo track-solo" data-track="{{ $track }}">
                                                            <i class="mdi mdi-headphones"></i> S
                                                        </button>

                                                        <button type="button" class="btn btn-sm btn-stem-mute track-mute {{ $isMutedByDefault ? 'active btn-warning' : 'btn-outline-warning' }}" data-track="{{ $track }}">
                                                            <i class="mdi mdi-volume-off"></i> {{ $isMutedByDefault ? __('Muted') : __('Mute') }}
                                                        </button>

                                                        <a
                                                            href="{{ $loadedRender['downloads'][$track] ?? '#' }}"
                                                            class="btn btn-sm btn-outline-primary {{ isset($loadedRender['downloads'][$track]) ? '' : 'disabled' }}"
                                                            download
                                                        >
                                                            <i class="mdi mdi-download"></i>
                                                        </a>
                                                    </div>
                                                </div>

                                                <div id="wave-{{ $track }}" class="stem-wave tts-wave"></div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="small text-muted mt-3">
                                    {{ __('Recent renders and active job state are cached in browser storage for instant reload.') }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4 mb-4">

            <div class="turbo-border mb-3">
                <div class="turbo-inner">
                    <div class="card mb-0">
                        <div class="card-body p-3 p-md-4 stem-param-rack">
                            <div class="d-flex align-items-center justify-content-between mb-3 gap-2 flex-wrap">
                                <div>
                                    <strong class="d-block">{{ __('Parameters') }}</strong>
                                    <small class="text-muted">{{ __('Tune the separation like an audio plugin before you render.') }}</small>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="small text-muted stem-param-sync" wire:loading wire:target="stems,model,stemCodec,stemBitrate">
                                        {{ __('Applying...') }}
                                    </span>
                                    <button wire:click="resetForm" class="btn btn-sm btn-outline-secondary" type="button">
                                        <i class="mdi mdi-refresh"></i> {{ __('Reset') }}
                                    </button>
                                </div>
                            </div>

                            <div class="stem-plugin-card mb-3">
                                <div class="stem-plugin-card__head">
                                    <div>
                                        <div class="stem-plugin-kicker">{{ __('Separation') }}</div>
                                        <h6 class="stem-plugin-title mb-0">{{ __('Stem Mode') }}</h6>
                                    </div>
                                    <span class="stem-plugin-value" id="stem-param-mode-copy">{{ $stems }} {{ __('outputs') }}</span>
                                </div>
                                <div
                                    class="stem-choice-grid"
                                    data-stem-choice-group="stems"
                                    data-current-value="{{ $stems }}"
                                >
                                    <button
                                        type="button"
                                        class="stem-choice {{ $stems === 2 ? 'is-active' : '' }}"
                                        data-stem-choice
                                        data-field="stems"
                                        data-value="2"
                                    >
                                        <span class="stem-choice-title">{{ __('2 Stems') }}</span>
                                        <span class="stem-choice-meta">{{ __('Vocals + Instrumental') }}</span>
                                    </button>

                                    <button
                                        type="button"
                                        class="stem-choice {{ $stems === 4 ? 'is-active' : '' }}"
                                        data-stem-choice
                                        data-field="stems"
                                        data-value="4"
                                    >
                                        <span class="stem-choice-title">{{ __('4 Stems') }}</span>
                                        <span class="stem-choice-meta">{{ __('Vocals, Drums, Bass, Other') }}</span>
                                    </button>
                                </div>
                                <small class="text-muted d-block mt-2">
                                    {{ __('2 = vocals + instrumental | 4 = vocals + drums + bass + other') }}
                                </small>
                            </div>

                            {{-- <div class="stem-plugin-card mb-3">
                                <div class="stem-plugin-card__head">
                                    <div>
                                        <div class="stem-plugin-kicker">Engine</div>
                                        <h6 class="stem-plugin-title mb-0">Stem Model</h6>
                                    </div>
                                </div>

                                <label class="stem-plugin-label" for="stem-model-select">Select Model</label>
                                <select id="stem-model-select" data-stem-select-field="model" class="form-select stem-plugin-select">
                                    <option value="htdemucs_ft" @selected($model === 'htdemucs_ft')>STEM V1.5</option>
                                </select>
                                <small class="form-text text-muted mt-2 d-block">Balanced for high-quality musical separation.</small>
                            </div> --}}

                            <div class="stem-plugin-card mb-3">
                                <div class="stem-plugin-card__head">
                                    <div>
                                        <div class="stem-plugin-kicker">{{ __('Output') }}</div>
                                        <h6 class="stem-plugin-title mb-0">{{ __('Format') }}</h6>
                                    </div>
                                </div>

                                <div
                                    class="stem-choice-grid stem-choice-grid--single"
                                    data-stem-choice-group="stemCodec"
                                    data-current-value="{{ $stemCodec }}"
                                >
                                    <button
                                        type="button"
                                        class="stem-choice {{ $stemCodec === 'mp3' ? 'is-active' : '' }}"
                                        data-stem-choice
                                        data-field="stemCodec"
                                        data-value="mp3"
                                    >
                                        <span class="stem-choice-title">{{ __('MP3') }}</span>
                                        <span class="stem-choice-meta">{{ __('Fast download-friendly delivery') }}</span>
                                    </button>
                                </div>
                            </div>

                            <div class="stem-plugin-card mb-0">
                                <div class="stem-plugin-card__head">
                                    <div>
                                        <div class="stem-plugin-kicker">{{ __('Export') }}</div>
                                        <h6 class="stem-plugin-title mb-0">{{ __('Bitrate') }}</h6>
                                    </div>
                                </div>

                                <label class="stem-plugin-label" for="stem-bitrate-select">{{ __('Quality') }}</label>
                                <select id="stem-bitrate-select" data-stem-select-field="stemBitrate" class="form-select stem-plugin-select">
                                    <option value="192k" @selected($stemBitrate === '192k')>192k</option>
                                </select>
                            </div>

                            <div class="wasr-cost-preview stem-cost-preview rounded-3 p-3 mt-3">
                                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
                                    <div>
                                        <div class="fw-semibold">{{ __('Estimated Cost') }}</div>
                                        <div class="small text-muted" id="stem-cost-mode-label">
                                            {{ $stems === 4 ? __('4-stem separation pricing') : __('2-stem separation pricing') }}
                                        </div>
                                    </div>

                                    <div class="badge wasr-badge-credits px-3 py-2">
                                        {{ number_format($creditsCost) }} {{ __('credits') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            <div class="turbo-border mb-3">
                <div class="turbo-inner">
                    <div class="card mb-0">
                        <div class="card-body p-3 p-md-4">
                            <div class="d-flex align-items-center justify-content-between mb-2 gap-2 flex-wrap">
                                <strong>{{ __('Recent Renders') }}</strong>
                                <span class="badge badge-secondary">{{ $this->recentRenders->total() }}</span>
                            </div>

                            <div class="mb-3">
                                <input
                                    type="text"
                                    class="form-control form-control-sm"
                                    placeholder="{{ __('Search...') }}"
                                    wire:model.live.debounce.300ms="search"
                                >
                            </div>

                            @if($this->recentRenders->count() === 0)
                                <div class="text-muted small text-center py-4">{{ __('No renders yet.') }}</div>
                            @else
                                <div class="list-group">
                                    @foreach($this->recentRenders as $render)
                                        <div class="list-group-item render-item stem-render-item {{ $latestFinishedJobId === (string) $render['id'] ? 'stem-render-item--latest' : '' }}">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div style="min-width:0; flex:1;">
                                                    <button
                                                        type="button"
                                                        class="btn btn-link p-0 text-left render-select-btn"
                                                        style="text-decoration:none; width:100%;"
                                                        wire:click="loadRender('{{ $render['id'] }}')"
                                                    >
                                                        <div class="d-flex align-items-center">
                                                            <i class="mdi mdi-music-note-eighth mr-2 render-cache-icon"></i>
                                                            <div>
                                                                <b class="d-block text-truncate" style="max-width: 200px;">{{ $render['input_name'] ?: __('Untitled audio') }}</b>
                                                                <small class="text-muted d-block">{{ __('Mode: :mode-Stem', ['mode' => $render['mode']]) }}</small>
                                                                <small class="text-muted d-block">{{ $render['created_at'] }}</small>
                                                            </div>
                                                        </div>
                                                    </button>
                                                </div>

                                                <div class="d-flex align-items-center gap-1 ml-2">
                                                    <a href="{{ $render['downloads']['all'] }}" class="btn btn-sm btn-outline-primary" title="{{ __('ZIP') }}">
                                                        <i class="mdi mdi-download"></i>
                                                    </a>

                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-outline-danger"
                                                        wire:click.stop="deleteRender('{{ $render['id'] }}')"
                                                        onclick="return confirm(@js(__('Delete this render? This will remove files and deduct storage.')));"
                                                    >
                                                        <i class="mdi mdi-delete"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                @if($this->recentRenders->hasPages())
                                    <div class="mt-3">
                                        {{ $this->recentRenders->links() }}
                                    </div>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    @if($showEliminateModal)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.45);">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('Eliminate current STEM job?') }}</h5>
                        <button type="button" class="btn-close" wire:click="closeEliminateModal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2">
                            {{ __('This will stop tracking the current STEM job and mark it as eliminated.') }}
                        </p>
                        <p class="mb-0 text-danger small">
                            {{ __('Credits are not refundable.') }}
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" wire:click="closeEliminateModal">{{ __('Cancel') }}</button>
                        <button type="button" class="btn btn-danger" wire:click="eliminateCurrentJob">
                            {{ __('Eliminate') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

@push('styles')
<link href="https://unpkg.com/filepond@^4/dist/filepond.min.css" rel="stylesheet">

<style>
    :root{
        --stem-primary: var(--bs-primary, #0d6efd);
    }

    .wasr-cost-preview{
        background: rgba(var(--bs-warning-rgb), .08);
        border: 1px solid rgba(var(--bs-warning-rgb), .22);
    }

    .wasr-badge-credits{
        background: rgba(var(--bs-warning-rgb), .18);
        color: var(--bs-warning-text-emphasis);
        border: 1px solid rgba(var(--bs-warning-rgb), .24);
    }

    .stem-job-progress{
        height: .6rem;
        background: rgba(255,255,255,.08);
        border-radius: 999px;
        overflow: hidden;
    }

    .stem-top-mini-stats{
        gap: .75rem;
    }

    .stem-mini-stat{
        min-width: 90px;
        padding: .65rem .85rem;
        border-radius: 12px;
        background: rgba(255,255,255,.04);
        border: 1px solid rgba(255,255,255,.08);
    }

    .stem-upload-meta{
        padding: .9rem 1rem;
        border-radius: 12px;
        background: rgba(255,255,255,.04);
        border: 1px solid rgba(255,255,255,.08);
    }

    .stem-param-sync{
        padding: .2rem .55rem;
        border-radius: 999px;
        border: 1px solid rgba(var(--bs-body-color-rgb), .08);
        background: rgba(var(--bs-body-color-rgb), .04);
    }

    .stem-plugin-card{
        padding: 1rem;
        border-radius: 18px;
        background:
            linear-gradient(180deg, rgba(255,255,255,.055), rgba(255,255,255,.02)),
            radial-gradient(circle at top left, rgba(var(--bs-primary-rgb), .14), transparent 46%);
        border: 1px solid rgba(255,255,255,.08);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.04), 0 16px 30px rgba(0,0,0,.08);
    }

    .stem-plugin-card__head{
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: .9rem;
    }

    .stem-plugin-kicker{
        font-size: .7rem;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: rgba(255,255,255,.62);
        margin-bottom: .2rem;
    }

    .stem-plugin-title{
        font-size: 1rem;
        font-weight: 700;
        letter-spacing: .01em;
    }

    .stem-plugin-value{
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 32px;
        padding: .35rem .7rem;
        border-radius: 999px;
        background: rgba(var(--bs-primary-rgb), .16);
        border: 1px solid rgba(var(--bs-primary-rgb), .25);
        color: rgba(255,255,255,.92);
        font-size: .75rem;
        font-weight: 700;
        white-space: nowrap;
    }

    .stem-plugin-label{
        display: block;
        margin-bottom: .5rem;
        font-size: .78rem;
        font-weight: 700;
        letter-spacing: .04em;
        text-transform: uppercase;
        color: rgba(255,255,255,.62);
    }

    .stem-plugin-select{
        min-height: 48px;
        border-radius: 14px;
        border: 1px solid rgba(255,255,255,.12);
        background: rgba(8, 14, 24, .72);
        color: var(--bs-body-color);
        font-weight: 600;
        box-shadow: none;
    }

    .stem-plugin-select:focus{
        border-color: rgba(var(--bs-primary-rgb), .45);
        box-shadow: 0 0 0 .18rem rgba(var(--bs-primary-rgb), .14);
        background: rgba(8, 14, 24, .88);
    }

    .stem-choice-grid{
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: .75rem;
    }

    .stem-choice-grid--single{
        grid-template-columns: 1fr;
    }

    .stem-choice{
        width: 100%;
        display: flex;
        flex-direction: column;
        gap: .32rem;
        padding: 1rem;
        border-radius: 16px;
        border: 1px solid rgba(255,255,255,.12);
        background: linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.025));
        color: var(--bs-body-color);
        text-align: left;
        transition: transform .16s ease, border-color .16s ease, box-shadow .16s ease, background .16s ease;
    }

    .stem-choice:hover{
        transform: translateY(-1px);
        border-color: rgba(var(--bs-primary-rgb), .28);
        box-shadow: 0 12px 24px rgba(0,0,0,.08);
    }

    .stem-choice.is-active{
        border-color: rgba(var(--bs-primary-rgb), .46);
        background: linear-gradient(180deg, rgba(var(--bs-primary-rgb), .2), rgba(var(--bs-primary-rgb), .09));
        box-shadow: inset 0 0 0 1px rgba(var(--bs-primary-rgb), .22), 0 14px 28px rgba(13,110,253,.12);
    }

    .stem-choice:focus-visible{
        outline: none;
        box-shadow: 0 0 0 .2rem rgba(var(--bs-primary-rgb), .18);
    }

    .stem-choice-title{
        font-size: .98rem;
        font-weight: 700;
    }

    .stem-choice-meta{
        font-size: .78rem;
        color: rgba(255,255,255,.68);
    }

    .stem-cost-preview{
        border-radius: 18px !important;
        background:
            linear-gradient(180deg, rgba(var(--bs-warning-rgb), .13), rgba(var(--bs-warning-rgb), .06)),
            radial-gradient(circle at top left, rgba(255,255,255,.08), transparent 38%);
    }

    .stem-player-container {
        background:
            linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02)),
            radial-gradient(circle at top left, rgba(var(--bs-primary-rgb), .12), transparent 34%);
        border: 1px solid rgba(255,255,255,.08);
        border-radius: 16px;
        padding: 20px;
    }

    .master-controls {
        background: rgba(5, 10, 18, .38);
        padding: 15px;
        border-radius: 12px;
        border: 1px solid rgba(255,255,255,.1);
    }

    .master-time {
        font-size: 1.1rem;
        font-weight: 600;
    }

    .stem-track {
        background: rgba(255,255,255,.035);
        border: 1px solid color-mix(in srgb, var(--stem-color) 40%, rgba(255,255,255,.10));
        border-left: 4px solid var(--stem-color);
        border-radius: 12px;
        padding: 12px;
        transition: all 0.2s ease;
    }

    .stem-track:hover {
        background: rgba(255,255,255,.06);
        transform: translateX(2px);
    }

    .stem-track-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .stem-track-label {
        flex: 1;
        min-width: 180px;
    }

    .stem-badge {
        background: color-mix(in srgb, var(--stem-color) 18%, rgba(255,255,255,.08));
        border: 1px solid color-mix(in srgb, var(--stem-color) 35%, rgba(255,255,255,.10));
        color: #fff;
        padding: 4px 12px;
        border-radius: 4px;
        font-weight: 600;
        font-size: 0.9rem;
    }

    .stem-track-controls {
        display: flex;
        gap: 5px;
        align-items: center;
    }

    .btn-stem-play,
    .btn-stem-solo,
    .btn-stem-mute {
        min-width: 44px;
        font-weight: 600;
        transition: all 0.2s ease;
    }

    .btn-stem-play:hover,
    .btn-stem-play.active {
        background: #198754 !important;
        border-color: #198754 !important;
        color: #fff !important;
    }

    .btn-stem-solo:hover,
    .btn-stem-solo.active,
    .track-solo.active {
        background: #ffc107 !important;
        border-color: #ffc107 !important;
        color: #000 !important;
    }

    .btn-stem-mute:hover,
    .btn-stem-mute.active {
        background: #dc3545;
        border-color: #dc3545;
        color: #fff;
    }

    .tts-wave,
    .stem-wave{
        min-height: 60px;
        height: 60px;
        margin-top: 8px;
        background: rgba(255,255,255,.02);
        border-radius: 6px;
        overflow: hidden;
        cursor: pointer;
    }

    .render-item,
    .stem-render-item{
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .render-item:hover,
    .stem-render-item:hover{
        background: rgba(0,123,255,.05);
        border-color: rgba(0,123,255,.2);
    }

    .render-select-btn {
        cursor: pointer !important;
    }

    .render-cache-icon {
        font-size: 1.2rem;
        transition: opacity 0.3s ease;
        opacity: .65;
    }

    .stem-render-item--latest{
        border-left: 3px solid var(--bs-primary);
    }

    @media (max-width: 575.98px) {
        .stem-choice-grid{
            grid-template-columns: 1fr;
        }
    }

    .gap-2 {
        gap: 0.5rem;
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
<script src="https://unpkg.com/wavesurfer.js@7/dist/wavesurfer.min.js"></script>

<script>
(function () {
    'use strict';

    if (!window.__STEM_POND__) {
        window.__STEM_POND__ = {
            pond: null,
            listenersBound: false,
            livewireBound: false,
            commitHooked: false,
            pluginsRegistered: false,
            formWatchBoot: false,
            bootTimer: null,
            paramTimers: {},
        };
    }

    const S = window.__STEM_POND__;
    const STEM_PARAM_I18N = {
        outputs: @js(__('outputs')),
        pricing4: @js(__('4-stem separation pricing')),
        pricing2: @js(__('2-stem separation pricing')),
        dragDrop: @js(__('Drag & Drop')),
        audioHere: @js(__('your audio file here')),
        or: @js(__('or')),
        browse: @js(__('Browse')),
        uploadFailed: @js(__('Upload failed')),
    };
    const FORM_KEY = 'stem_form_state_v3';
    const FORM_TTL = 7 * 24 * 60 * 60 * 1000;
    const PARAM_DEFAULTS = {
        stems: 4,
        model: 'htdemucs_ft',
        stemCodec: 'mp3',
        stemBitrate: '192k',
    };

    if (!S.pluginsRegistered) {
        FilePond.registerPlugin(
            FilePondPluginFileValidateType,
            FilePondPluginFileValidateSize
        );
        S.pluginsRegistered = true;
    }

    function getStemComponent() {
        if (!window.Livewire) return null;

        const root = document.getElementById('stem-page-root');
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

    function normalizeState(state = {}) {
        return {
            stems: safeInt(state.stems, PARAM_DEFAULTS.stems) === 2 ? 2 : 4,
            model: String(state.model || PARAM_DEFAULTS.model),
            stemCodec: String(state.stemCodec || PARAM_DEFAULTS.stemCodec),
            stemBitrate: String(state.stemBitrate || PARAM_DEFAULTS.stemBitrate),
        };
    }

    function getStateFromDom() {
        const stemsGroup = document.querySelector('[data-stem-choice-group="stems"]');
        const codecGroup = document.querySelector('[data-stem-choice-group="stemCodec"]');
        const modelSelect = document.querySelector('[data-stem-select-field="model"]');
        const bitrateSelect = document.querySelector('[data-stem-select-field="stemBitrate"]');

        if (!stemsGroup && !codecGroup && !modelSelect && !bitrateSelect) {
            return null;
        }

        return normalizeState({
            stems: stemsGroup?.dataset.currentValue || PARAM_DEFAULTS.stems,
            model: modelSelect?.value || PARAM_DEFAULTS.model,
            stemCodec: codecGroup?.dataset.currentValue || PARAM_DEFAULTS.stemCodec,
            stemBitrate: bitrateSelect?.value || PARAM_DEFAULTS.stemBitrate,
        });
    }

    function getStateFromLivewire(lw = getStemComponent()) {
        if (!lw || typeof lw.get !== 'function') return null;

        return normalizeState({
            stems: lw.get('stems'),
            model: lw.get('model'),
            stemCodec: lw.get('stemCodec'),
            stemBitrate: lw.get('stemBitrate'),
        });
    }

    function updateParameterMicrocopy(state) {
        const modeCopy = document.getElementById('stem-param-mode-copy');
        const costLabel = document.getElementById('stem-cost-mode-label');

        if (modeCopy) {
            modeCopy.textContent = `${state.stems} ${STEM_PARAM_I18N.outputs}`;
        }

        if (costLabel) {
            costLabel.textContent = state.stems === 4
                ? STEM_PARAM_I18N.pricing4
                : STEM_PARAM_I18N.pricing2;
        }
    }

    function applyParameterUI(stateLike = null) {
        const state = normalizeState(
            stateLike
            || getStateFromDom()
            || getStateFromLivewire()
            || PARAM_DEFAULTS
        );

        document.querySelectorAll('[data-stem-choice-group]').forEach((group) => {
            const field = group.dataset.stemChoiceGroup;
            const currentValue = String(state[field] ?? group.dataset.currentValue ?? '');

            group.dataset.currentValue = currentValue;

            group.querySelectorAll('[data-stem-choice]').forEach((button) => {
                const active = button.dataset.value === currentValue;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        });

        document.querySelectorAll('[data-stem-select-field]').forEach((select) => {
            const field = select.dataset.stemSelectField;
            const currentValue = String(state[field] ?? '');

            if (currentValue && select.value !== currentValue) {
                select.value = currentValue;
            }
        });

        updateParameterMicrocopy(state);

        return state;
    }

    function persistState(stateLike = null) {
        try {
            const state = normalizeState(
                stateLike
                || getStateFromDom()
                || getStateFromLivewire()
                || PARAM_DEFAULTS
            );

            localStorage.setItem(FORM_KEY, JSON.stringify({
                ...state,
                ts: Date.now(),
            }));
        } catch (_) {}
    }

    function queueParameterSync(field, value) {
        const lw = getStemComponent();
        if (!lw || typeof lw.set !== 'function') return;

        clearTimeout(S.paramTimers[field]);
        S.paramTimers[field] = setTimeout(() => {
            try {
                lw.set(field, field === 'stems' ? safeInt(value, PARAM_DEFAULTS.stems) : String(value));
            } catch (_) {}
        }, 120);
    }

    function bindParameterControls() {
        applyParameterUI();

        document.querySelectorAll('[data-stem-choice]').forEach((button) => {
            if (button.dataset.bound === '1') return;
            button.dataset.bound = '1';

            button.addEventListener('click', () => {
                const field = button.dataset.field || '';
                const rawValue = button.dataset.value || '';
                if (!field || rawValue === '') return;

                const nextState = normalizeState({
                    ...(getStateFromDom() || getStateFromLivewire() || PARAM_DEFAULTS),
                    [field]: field === 'stems'
                        ? safeInt(rawValue, PARAM_DEFAULTS.stems)
                        : rawValue,
                });

                applyParameterUI(nextState);
                persistState(nextState);
                queueParameterSync(field, nextState[field]);
            });
        });

        document.querySelectorAll('[data-stem-select-field]').forEach((select) => {
            if (select.dataset.bound === '1') return;
            select.dataset.bound = '1';

            select.addEventListener('change', () => {
                const field = select.dataset.stemSelectField || '';
                if (!field) return;

                const nextState = normalizeState({
                    ...(getStateFromDom() || getStateFromLivewire() || PARAM_DEFAULTS),
                    [field]: select.value,
                });

                applyParameterUI(nextState);
                persistState(nextState);
                queueParameterSync(field, nextState[field]);
            });
        });
    }

    function formSave() {
        persistState();
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
        if (!saved) {
            applyParameterUI(getStateFromLivewire() || PARAM_DEFAULTS);
            return;
        }

        const lw = getStemComponent();
        applyParameterUI(saved);

        if (!lw || typeof lw.set !== 'function') return;

        try {
            const state = normalizeState(saved);
            lw.set('stems', state.stems);
            lw.set('model', state.model);
            lw.set('stemCodec', state.stemCodec);
            lw.set('stemBitrate', state.stemBitrate);
        } catch (_) {}
    }

    function watchAndPersistForm() {
        if (S.formWatchBoot) return;

        const lw = getStemComponent();
        if (!lw || typeof lw.$watch !== 'function') return;

        S.formWatchBoot = true;

        let timer = null;
        const debouncedSave = () => {
            clearTimeout(timer);
            timer = setTimeout(() => {
                formSave();
                applyParameterUI(getStateFromLivewire() || getStateFromDom() || PARAM_DEFAULTS);
            }, 250);
        };

        ['stems', 'model', 'stemCodec', 'stemBitrate'].forEach((field) => {
            try {
                lw.$watch(field, debouncedSave);
            } catch (_) {}
        });
    }

    function bootPond() {
        const input = document.getElementById('stem-audio-pond');
        if (!input) return;

        destroyPond();

        const lw = getStemComponent();
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
                    <div class="mb-1"><strong>${STEM_PARAM_I18N.dragDrop}</strong> ${STEM_PARAM_I18N.audioHere}</div>
                    <div class="small text-muted">${STEM_PARAM_I18N.or} <span class="filepond--label-action">${STEM_PARAM_I18N.browse}</span></div>
                </div>
            `,
            server: {
                process: (fieldName, file, metadata, load, error, progress, abort) => {
                    lw.upload(
                        'audioFile',
                        file,
                        () => load(file.name),
                        (e) => error(typeof e === 'string' ? e : STEM_PARAM_I18N.uploadFailed),
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

    function bootStemFilePondPage() {
        if (S.bootTimer) {
            clearTimeout(S.bootTimer);
        }

        S.bootTimer = setTimeout(() => {
            S.bootTimer = null;
            formRestoreIfNeeded();
            bindParameterControls();
            watchAndPersistForm();
            bootPond();
        }, 0);
    }

    if (!S.listenersBound) {
        S.listenersBound = true;

        document.addEventListener('livewire:initialized', bootStemFilePondPage);
        document.addEventListener('livewire:navigated', bootStemFilePondPage);
        document.addEventListener('livewire:navigating', () => {
            formSave();
            S.formWatchBoot = false;
            destroyPond();
        });
    }

    if (window.Livewire && !S.livewireBound) {
        S.livewireBound = true;

        Livewire.on('stem-audio-file-cleared', () => {
            if (S.pond) {
                try { S.pond.removeFiles(); } catch (_) {}
            }
        });

        Livewire.on('stem-form-reset', () => {
            if (S.pond) {
                try { S.pond.removeFiles(); } catch (_) {}
            }

            applyParameterUI(PARAM_DEFAULTS);
        });

        Livewire.on('stem-form-state-clear', () => {
            formClear();
            applyParameterUI(PARAM_DEFAULTS);
        });

        if (!S.commitHooked && typeof Livewire.hook === 'function') {
            S.commitHooked = true;

            Livewire.hook('commit', ({ succeed }) => {
                succeed(() => {
                    requestAnimationFrame(() => {
                        formSave();
                        bindParameterControls();
                        applyParameterUI(getStateFromLivewire() || getStateFromDom() || PARAM_DEFAULTS);
                        const input = document.getElementById('stem-audio-pond');
                        if (input && !S.pond) {
                            bootPond();
                        }
                    });
                });
            });
        }
    }

    bootStemFilePondPage();
})();
</script>
@endpush

@push('scripts')
<script>
(function () {
    'use strict';

    if (!window.__STEM_RENDER_PAGE__) {
        window.__STEM_RENDER_PAGE__ = {
            medias: {},
            waves: {},
            trackState: {},
            currentRender: null,
            audioContext: null,
            isPlaying: false,
            isSyncSeeking: false,
            isLoadingBuffers: false,
            auditionTrack: null,
            eventsBound: false,
            commitHooked: false,
            masterTicker: null,
            listenersBound: false,
            bootTimer: null,
            masterTime: 0,
            duration: 0,
            leadTrack: null,
            transportStartedAt: 0,
            transportOffset: 0,
            assetGeneration: 0,
            sourceGeneration: 0,
            transportRequestId: 0,
            loadingRenderId: null,
        };
    }

    const S = window.__STEM_RENDER_PAGE__;
    const STEM_RENDER_I18N = {
        preparing: @js(__('Preparing...')),
        playAll: @js(__('Play All')),
        pauseAll: @js(__('Pause All')),
        play: @js(__('Play')),
        pause: @js(__('Pause')),
        loading: @js(__('Loading')),
        muted: @js(__('Muted')),
        mute: @js(__('Mute')),
        original: @js(__('Original')),
        vocals: @js(__('Vocals')),
        instrumental: @js(__('Instrumental')),
        drums: @js(__('Drums')),
        bass: @js(__('Bass')),
        other: @js(__('Other')),
        originalDescription: @js(__('Original uploaded audio - muted by default')),
        separatedTrack: @js(__('Separated output track')),
        seekAll: @js(__('Click to seek all stems together')),
    };
    const RENDER_KEY = 'stem_render_cache_v2';
    const LAST_RENDER_KEY = 'stem_last_render_id_v1';
    const JOB_KEY = 'stem_spa_job_v1';
    const RENDER_TTL = 7 * 24 * 60 * 60 * 1000;
    const JOB_TTL = 30 * 60 * 1000;
    const MAX_RENDER_CACHE = 12;

    const STEM_COLORS = {
        original: '#95a5a6',
        vocals: '#e74c3c',
        instrumental: '#3498db',
        drums: '#f1c40f',
        bass: '#9b59b6',
        other: '#1abc9c'
    };

    function getStemComponent() {
        if (!window.Livewire) return null;

        const root = document.getElementById('stem-page-root');
        if (!root) return null;

        const wireId = root.getAttribute('wire:id');
        if (!wireId) return null;

        try {
            return window.Livewire.find(wireId);
        } catch (_) {
            return null;
        }
    }

    function readInitialRender() {
        const node = document.getElementById('stem-initial-render-data');
        if (!node) return null;

        try {
            return JSON.parse(node.textContent || 'null');
        } catch (e) {
            console.warn('[STEM] Failed to parse initial render payload', e);
            return null;
        }
    }

    function clamp(value, min, max) {
        return Math.min(Math.max(value, min), max);
    }

    function formatTime(sec) {
        sec = Math.max(0, Number(sec || 0));
        const m = String(Math.floor(sec / 60)).padStart(2, '0');
        const s = String(Math.floor(sec % 60)).padStart(2, '0');
        return `${m}:${s}`;
    }

    function updateLatestHeader(render) {
        const titleEl = document.getElementById('stem-latest-title');
        const labelEl = document.getElementById('stem-current-render-label');
        const idEl = document.getElementById('stem-current-render-id');
        const downloadAll = document.getElementById('stem-download-all');
        const readyEl = document.getElementById('stem-latest-ready');
        const hiddenIdEl = document.getElementById('stem-latest-render-id');

        if (titleEl) {
            titleEl.textContent = render?.input_name || 'No output selected';
        }

        if (labelEl) {
            labelEl.classList.toggle('d-none', !(render && render.id));
        }

        if (idEl) {
            idEl.textContent = render?.id ? `#${render.id}` : '';
        }

        if (downloadAll) {
            if (render?.downloads?.all) {
                downloadAll.href = render.downloads.all;
                downloadAll.classList.remove('disabled');
            } else {
                downloadAll.href = '#';
                downloadAll.classList.add('disabled');
            }
        }

        if (readyEl) {
            readyEl.value = render?.id ? '1' : '0';
        }

        if (hiddenIdEl) {
            hiddenIdEl.value = render?.id || '';
        }
    }

    function updateMasterTime(current = S.masterTime, duration = S.duration) {
        const currentEl = document.getElementById('master-current');
        const durationEl = document.getElementById('master-duration');

        if (!currentEl || !durationEl) return;

        currentEl.textContent = formatTime(current);
        durationEl.textContent = formatTime(duration);
    }

    function ensureAudioContext() {
        if (S.audioContext) {
            return S.audioContext;
        }

        const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextCtor) {
            return null;
        }

        S.audioContext = new AudioContextCtor();

        return S.audioContext;
    }

    async function resumeAudioContext() {
        const context = ensureAudioContext();
        if (!context) return null;

        if (context.state === 'suspended') {
            try {
                await context.resume();
            } catch (_) {}
        }

        return context;
    }

    function getLeadTrack() {
        if (S.leadTrack && S.medias[S.leadTrack]) {
            return S.leadTrack;
        }

        const firstTrack = Object.keys(S.medias)[0] || null;
        if (!firstTrack) return null;

        S.leadTrack = firstTrack;

        return firstTrack;
    }

    function getTransportTime() {
        if (!S.isPlaying || !S.audioContext) {
            return clamp(S.masterTime, 0, S.duration || Math.max(S.masterTime, 0));
        }

        return clamp(
            (S.audioContext.currentTime - S.transportStartedAt) + S.transportOffset,
            0,
            S.duration || 0
        );
    }

    function syncWaveProgress(track, seconds = S.masterTime) {
        const wave = S.waves[track];
        if (!wave) return;

        const duration = Number(
            S.medias[track]?.duration
            || S.duration
            || wave.getDuration?.()
            || 0
        );

        if (!(duration > 0)) return;

        const ratio = clamp(seconds / duration, 0, 1);
        if (Math.abs((wave.__stemRatio ?? -1) - ratio) < 0.004) return;

        wave.__stemRatio = ratio;

        try {
            wave.seekTo(ratio);
        } catch (_) {}
    }

    function syncAllWaveProgress(seconds = S.masterTime) {
        Object.keys(S.waves).forEach((track) => {
            syncWaveProgress(track, seconds);
        });
    }

    function updateMasterControls() {
        const hasRender = !!S.currentRender && Object.keys(S.medias).length > 0;
        const busy = S.isLoadingBuffers;
        const playBtn = document.getElementById('stem-master-play');
        const stopBtn = document.getElementById('stem-master-stop');

        if (playBtn) {
            playBtn.disabled = !hasRender || busy;
            playBtn.classList.toggle('btn-success', !busy && !(S.isPlaying && !S.auditionTrack));
            playBtn.classList.toggle('btn-warning', !busy && S.isPlaying && !S.auditionTrack);
            playBtn.classList.toggle('btn-outline-secondary', busy);

            if (busy) {
                playBtn.innerHTML = `<span class="spinner-border spinner-border-sm mr-1"></span> ${STEM_RENDER_I18N.preparing}`;
            } else {
                playBtn.innerHTML = S.isPlaying && !S.auditionTrack
                    ? `<i class="mdi mdi-pause"></i> ${STEM_RENDER_I18N.pauseAll}`
                    : `<i class="mdi mdi-play"></i> ${STEM_RENDER_I18N.playAll}`;
            }
        }

        if (stopBtn) {
            stopBtn.disabled = !hasRender || busy;
        }
    }

    function stopSources() {
        S.sourceGeneration += 1;

        Object.values(S.medias).forEach((media) => {
            if (media.source) {
                try {
                    media.source.onended = null;
                    media.source.stop();
                } catch (_) {}

                try {
                    media.source.disconnect();
                } catch (_) {}

                media.source = null;
            }

            if (media.gain) {
                try {
                    media.gain.disconnect();
                } catch (_) {}

                media.gain = null;
            }
        });
    }

    function finishTransport() {
        S.transportRequestId += 1;
        S.isPlaying = false;
        S.masterTime = S.duration;
        S.transportOffset = S.duration;

        stopMasterTicker();
        stopSources();
        syncAllWaveProgress(S.masterTime);
        updateMasterTime(S.masterTime, S.duration);
        updateMasterControls();
        updateTrackButtonStates();
    }

    function stopMasterTicker() {
        if (S.masterTicker) {
            cancelAnimationFrame(S.masterTicker);
            S.masterTicker = null;
        }
    }

    function startMasterTicker() {
        stopMasterTicker();

        const tick = () => {
            if (!S.isPlaying) {
                S.masterTicker = null;
                return;
            }

            S.masterTime = getTransportTime();
            updateMasterTime(S.masterTime, S.duration);
            syncAllWaveProgress(S.masterTime);

            if (S.duration > 0 && S.masterTime >= (S.duration - 0.03)) {
                finishTransport();
                return;
            }

            S.masterTicker = requestAnimationFrame(tick);
        };

        S.masterTicker = requestAnimationFrame(tick);
    }

    function destroyPlayers() {
        S.transportRequestId += 1;
        stopMasterTicker();
        stopSources();

        Object.values(S.waves).forEach((wave) => {
            try { wave.destroy(); } catch (_) {}
        });

        Object.values(S.medias).forEach((media) => {
            if (media?.waveUrl) {
                try { URL.revokeObjectURL(media.waveUrl); } catch (_) {}
            }
        });

        S.medias = {};
        S.waves = {};
        S.trackState = {};
        S.currentRender = null;
        S.isPlaying = false;
        S.isSyncSeeking = false;
        S.isLoadingBuffers = false;
        S.auditionTrack = null;
        S.masterTime = 0;
        S.duration = 0;
        S.leadTrack = null;
        S.transportStartedAt = 0;
        S.transportOffset = 0;
        S.assetGeneration += 1;
        S.loadingRenderId = null;

        updateMasterControls();
        updateMasterTime(0, 0);
    }

    function defaultStateForTracks(tracks) {
        const state = {};

        (tracks || []).forEach((track) => {
            state[track] = {
                mute: track === 'original',
                solo: false,
                volume: track === 'original' ? 0 : 1,
            };
        });

        return state;
    }

    function trackLabel(track) {
        switch (track) {
            case 'original': return STEM_RENDER_I18N.original.toUpperCase();
            case 'vocals': return STEM_RENDER_I18N.vocals.toUpperCase();
            case 'instrumental': return STEM_RENDER_I18N.instrumental.toUpperCase();
            case 'drums': return STEM_RENDER_I18N.drums.toUpperCase();
            case 'bass': return STEM_RENDER_I18N.bass.toUpperCase();
            case 'other': return STEM_RENDER_I18N.other.toUpperCase();
            default: return String(track || '').toUpperCase();
        }
    }

    function trackDescription(track) {
        return track === 'original'
            ? STEM_RENDER_I18N.originalDescription
            : STEM_RENDER_I18N.separatedTrack;
    }

    function ensureTrackRows(render) {
        const tracksEl = document.getElementById('stem-tracks');
        if (!tracksEl) return false;

        tracksEl.innerHTML = (render.tracks || []).map((track) => {
            const downloadUrl = render.downloads?.[track] || '#';
            const muted = track === 'original';
            const color = STEM_COLORS[track] || '#0d6efd';

            return `
                <div class="stem-track glass-load-stem mb-3 stem-track-row" data-track="${track}" style="--stem-color:${color};">
                    <div class="stem-track-header">
                        <div class="stem-track-label">
                            <span class="stem-badge">${trackLabel(track)}</span>
                            <div class="small text-muted mt-1">${trackDescription(track)}</div>
                        </div>
                        <div class="stem-track-controls d-flex gap-2 flex-wrap">
                            <button type="button" class="btn btn-sm btn-stem-play track-play btn-outline-success" data-track="${track}">
                                <i class="mdi mdi-play"></i> ${STEM_RENDER_I18N.play}
                            </button>
                            <button type="button" class="btn btn-sm btn-stem-solo track-solo" data-track="${track}">
                                <i class="mdi mdi-headphones"></i> S
                            </button>
                            <button type="button" class="btn btn-sm btn-stem-mute track-mute ${muted ? 'active btn-warning' : 'btn-outline-warning'}" data-track="${track}">
                                <i class="mdi mdi-volume-off"></i> ${muted ? STEM_RENDER_I18N.muted : STEM_RENDER_I18N.mute}
                            </button>
                            <a href="${downloadUrl}" class="btn btn-sm btn-outline-primary${downloadUrl === '#' ? ' disabled' : ''}">
                                <i class="mdi mdi-download"></i>
                            </a>
                        </div>
                    </div>
                    <div id="wave-${track}" class="stem-wave tts-wave" title="${STEM_RENDER_I18N.seekAll}"></div>
                </div>
            `}).join('');

        return true;
    }

    function setPlayerVisibility(hasRender) {
        const empty = document.getElementById('stem-empty-state');
        const wrap = document.getElementById('stem-tracks-wrapper');
        const playBtn = document.getElementById('stem-master-play');
        const stopBtn = document.getElementById('stem-master-stop');
        const downloadAll = document.getElementById('stem-download-all');

        if (empty) empty.classList.toggle('d-none', !!hasRender);
        if (wrap) wrap.classList.toggle('d-none', !hasRender);
        if (playBtn) playBtn.disabled = !hasRender;
        if (stopBtn) stopBtn.disabled = !hasRender;

        if (downloadAll && !hasRender) {
            downloadAll.href = '#';
            downloadAll.classList.add('disabled');
        }
    }

    function saveRenderCache(render) {
        if (!render || !render.id) return;

        try {
            const cache = JSON.parse(localStorage.getItem(RENDER_KEY) || '{}');
            cache[render.id] = { ...render, ts: Date.now() };

            const entries = Object.entries(cache)
                .filter(([, value]) => value && (Date.now() - (value.ts || 0)) < RENDER_TTL)
                .sort((a, b) => (a[1].ts || 0) - (b[1].ts || 0));

            while (entries.length > MAX_RENDER_CACHE) {
                const [oldestKey] = entries.shift();
                delete cache[oldestKey];
            }

            localStorage.setItem(RENDER_KEY, JSON.stringify(cache));
            localStorage.setItem(LAST_RENDER_KEY, render.id);
        } catch (_) {}
    }

    function loadRenderFromCache(id) {
        if (!id) return null;

        try {
            const cache = JSON.parse(localStorage.getItem(RENDER_KEY) || '{}');
            const render = cache[id] || null;

            if (!render) return null;
            if (Date.now() - (render.ts || 0) > RENDER_TTL) {
                delete cache[id];
                localStorage.setItem(RENDER_KEY, JSON.stringify(cache));
                return null;
            }

            return render;
        } catch (_) {
            return null;
        }
    }

    function loadLatestCachedRender() {
        try {
            const lastId = localStorage.getItem(LAST_RENDER_KEY);
            return loadRenderFromCache(lastId);
        } catch (_) {
            return null;
        }
    }

    function spaSave(data) {
        if (!data || !data.jobId) return;

        try {
            localStorage.setItem(JOB_KEY, JSON.stringify({ ...data, ts: Date.now() }));
        } catch (_) {}
    }

    function spaLoad() {
        try {
            const raw = localStorage.getItem(JOB_KEY);
            if (!raw) return null;

            const data = JSON.parse(raw);
            if (!data || Date.now() - (data.ts || 0) > JOB_TTL) {
                localStorage.removeItem(JOB_KEY);
                return null;
            }

            return data;
        } catch (_) {
            return null;
        }
    }

    function spaClear() {
        try { localStorage.removeItem(JOB_KEY); } catch (_) {}
    }

    function spaRestoreIfNeeded() {
        const saved = spaLoad();
        if (!saved || !saved.jobId) return;

        const activeStatuses = ['queued', 'running', 'saving'];
        if (!activeStatuses.includes(saved.status)) return;

        const lw = getStemComponent();
        if (!lw || typeof lw.set !== 'function') return;

        try {
            lw.set('currentJobId', saved.jobId);
            lw.set('providerJobId', saved.providerJobId || null);
            lw.set('currentStatus', saved.status);
            lw.set('jobFinished', false);
            lw.set('showJobStatus', true);
            lw.set('currentProgress', saved.progress || 10);
        } catch (_) {}
    }

    function applyStemMix() {
        const tracks = Object.keys(S.trackState);
        const soloed = tracks.filter((track) => S.trackState[track]?.solo);

        tracks.forEach((track) => {
            let volume = 1;

            if (S.auditionTrack) {
                volume = S.auditionTrack === track ? 1 : 0;
            } else if (soloed.length > 0) {
                volume = soloed.includes(track) ? 1 : 0;
            } else if (S.trackState[track]?.mute) {
                volume = 0;
            }

            S.trackState[track].volume = volume;

            const context = S.audioContext;
            const gain = S.medias[track]?.gain;
            if (!context || !gain) return;

            try {
                gain.gain.cancelScheduledValues(context.currentTime);
                gain.gain.setTargetAtTime(volume, context.currentTime, 0.015);
            } catch (_) {
                try {
                    gain.gain.value = volume;
                } catch (_) {}
            }
        });
    }

    function seekTransport(seconds) {
        const target = clamp(Number(seconds || 0), 0, S.duration || Math.max(Number(seconds || 0), 0));
        S.masterTime = target;
        S.transportOffset = target;
        updateMasterTime(S.masterTime, S.duration);
        syncAllWaveProgress(S.masterTime);

        if (S.isPlaying) {
            playTransport(S.auditionTrack);
        }
    }

    function pauseTransport({ preserveTime = true } = {}) {
        const pauseAt = preserveTime
            ? getTransportTime()
            : 0;

        S.transportRequestId += 1;
        S.isPlaying = false;
        S.transportOffset = pauseAt;
        stopMasterTicker();
        stopSources();

        S.masterTime = pauseAt;
        syncAllWaveProgress(S.masterTime);
        updateMasterTime(S.masterTime, S.duration);
        updateMasterControls();
        updateTrackButtonStates();
    }

    function stopTransport() {
        pauseTransport({ preserveTime: false });
    }

    async function playTransport(auditionTrack = S.auditionTrack) {
        if (!S.currentRender || S.isLoadingBuffers || Object.keys(S.medias).length === 0) return;

        const requestId = S.transportRequestId + 1;
        S.transportRequestId = requestId;

        const context = await resumeAudioContext();
        if (!context || requestId !== S.transportRequestId) return;

        S.auditionTrack = auditionTrack || null;
        S.masterTime = clamp(S.masterTime, 0, S.duration || Math.max(S.masterTime, 0));
        S.transportOffset = S.masterTime;

        stopSources();
        if (requestId !== S.transportRequestId) return;

        const generation = S.sourceGeneration;
        const offset = S.transportOffset;
        const leadTrack = getLeadTrack();

        Object.entries(S.medias).forEach(([track, media]) => {
            if (!media?.buffer) return;

            const source = context.createBufferSource();
            const gain = context.createGain();

            source.buffer = media.buffer;
            source.connect(gain);
            gain.connect(context.destination);

            media.source = source;
            media.gain = gain;

            const volume = S.trackState[track]?.volume ?? 1;
            gain.gain.value = volume;

            source.onended = () => {
                if (generation !== S.sourceGeneration || !S.isPlaying) return;
                if (track !== leadTrack) return;
                finishTransport();
            };

            try {
                source.start(0, offset);
            } catch (_) {}
        });

        S.transportStartedAt = context.currentTime;
        S.isPlaying = true;

        applyStemMix();
        updateMasterTime(S.masterTime, S.duration);
        syncAllWaveProgress(S.masterTime);
        updateMasterControls();
        updateTrackButtonStates();
        startMasterTicker();
    }

    function guessMimeType(url, contentType = '') {
        if (contentType) {
            return contentType.split(';')[0];
        }

        const lowered = String(url || '').toLowerCase();
        if (lowered.endsWith('.wav')) return 'audio/wav';
        if (lowered.endsWith('.ogg')) return 'audio/ogg';
        if (lowered.endsWith('.aac')) return 'audio/aac';
        if (lowered.endsWith('.m4a') || lowered.endsWith('.mp4')) return 'audio/mp4';
        return 'audio/mpeg';
    }

    async function createMedia(track, url) {
        if (!url) return null;

        const context = ensureAudioContext();
        if (!context) return null;

        const response = await fetch(url, { credentials: 'same-origin' });
        if (!response.ok) {
            throw new Error(`Failed to load ${track}`);
        }

        const mimeType = guessMimeType(url, response.headers.get('content-type') || '');
        const arrayBuffer = await response.arrayBuffer();
        const decoded = await context.decodeAudioData(arrayBuffer.slice(0));
        const waveUrl = URL.createObjectURL(new Blob([arrayBuffer], { type: mimeType }));

        return {
            buffer: decoded,
            duration: Number(decoded.duration || 0),
            source: null,
            gain: null,
            waveUrl,
        };
    }

    function createWave(track, url) {
        const container = document.getElementById(`wave-${track}`);
        if (!container || !url || !window.WaveSurfer) return null;

        const color = STEM_COLORS[track] || '#4f46e5';
        const options = {
            container,
            waveColor: color,
            progressColor: color,
            cursorColor: color,
            height: 60,
            normalize: true,
            autoScroll: false,
            interact: false,
            barWidth: 2,
            barGap: 2,
            barRadius: 2,
        };

        try {
            const wave = WaveSurfer.create({ ...options, url });

            wave.on('ready', () => {
                const duration = Number(S.medias[track]?.duration || wave.getDuration?.() || 0);
                if (duration > 0) {
                    S.duration = Math.max(S.duration, duration);
                    updateMasterTime(S.masterTime, S.duration);
                }

                syncWaveProgress(track, S.masterTime);
            });

            return wave;
        } catch (_) {
            return null;
        }
    }

    function bindWaveSeekHandlers() {
        document.querySelectorAll('.stem-wave').forEach((waveEl) => {
            if (waveEl.dataset.seekBound === '1') return;
            waveEl.dataset.seekBound = '1';

            waveEl.addEventListener('click', (event) => {
                const rect = waveEl.getBoundingClientRect();
                if (rect.width <= 0) return;

                const ratio = clamp((event.clientX - rect.left) / rect.width, 0, 1);
                const duration = S.duration > 0 ? S.duration : 0;

                if (!(duration > 0)) return;

                seekTransport(duration * ratio);
            });
        });
    }

    function updateTrackButtonStates() {
        document.querySelectorAll('.track-play').forEach((button) => {
            const track = button.dataset.track;
            const active = S.auditionTrack === track;
            const ready = !!S.medias[track]?.buffer;

            button.classList.toggle('active', active);
            button.classList.toggle('btn-success', active);
            button.classList.toggle('btn-outline-success', !active);
            button.disabled = !ready || S.isLoadingBuffers;
            button.setAttribute('aria-pressed', active ? 'true' : 'false');

            if (!ready || S.isLoadingBuffers) {
                button.innerHTML = `<span class="spinner-border spinner-border-sm mr-1"></span> ${STEM_RENDER_I18N.loading}`;
                return;
            }

            button.innerHTML = active && S.isPlaying
                ? `<i class="mdi mdi-pause"></i> ${STEM_RENDER_I18N.pause}`
                : `<i class="mdi mdi-play"></i> ${STEM_RENDER_I18N.play}`;
        });

        document.querySelectorAll('.track-solo').forEach((button) => {
            const track = button.dataset.track;
            const active = !!S.trackState[track]?.solo;

            button.classList.toggle('active', active);
            button.classList.toggle('btn-primary', active);
            button.classList.toggle('btn-outline-primary', !active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        document.querySelectorAll('.track-mute').forEach((button) => {
            const track = button.dataset.track;
            const muted = !!S.trackState[track]?.mute;

            button.classList.toggle('active', muted);
            button.classList.toggle('btn-warning', muted);
            button.classList.toggle('btn-outline-warning', !muted);
            button.setAttribute('aria-pressed', muted ? 'true' : 'false');
            button.innerHTML = `<i class="mdi mdi-volume-off"></i> ${muted ? STEM_RENDER_I18N.muted : STEM_RENDER_I18N.mute}`;
        });
    }

    function bindTrackButtons() {
        document.querySelectorAll('.track-play').forEach((button) => {
            button.onclick = async () => {
                const track = button.dataset.track;
                if (!S.trackState[track] || S.isLoadingBuffers) return;

                if (S.auditionTrack === track && S.isPlaying) {
                    pauseTransport({ preserveTime: true });
                    return;
                }

                S.auditionTrack = track;
                await playTransport(track);
            };
        });

        document.querySelectorAll('.track-solo').forEach((button) => {
            button.onclick = () => {
                const track = button.dataset.track;
                if (!S.trackState[track]) return;

                S.trackState[track].solo = !S.trackState[track].solo;
                applyStemMix();
                updateTrackButtonStates();
            };
        });

        document.querySelectorAll('.track-mute').forEach((button) => {
            button.onclick = () => {
                const track = button.dataset.track;
                if (!S.trackState[track]) return;

                S.trackState[track].mute = !S.trackState[track].mute;
                applyStemMix();
                updateTrackButtonStates();
            };
        });

        const playBtn = document.getElementById('stem-master-play');
        const stopBtn = document.getElementById('stem-master-stop');

        if (playBtn) {
            playBtn.onclick = async () => {
                if (S.isPlaying && !S.auditionTrack) {
                    pauseTransport({ preserveTime: true });
                    return;
                }

                S.auditionTrack = null;
                await playTransport(null);
            };
        }

        if (stopBtn) {
            stopBtn.onclick = () => {
                stopTransport();
            };
        }

        bindWaveSeekHandlers();
        updateMasterControls();
        updateTrackButtonStates();
    }

    function loadStemRender(render, options = {}) {
        if (!render || !render.id || !Array.isArray(render.tracks)) return;

        const { persist = true } = options;
        const assetGeneration = S.assetGeneration + 1;
        destroyPlayers();
        if (!ensureTrackRows(render)) return;

        S.currentRender = render;
        S.assetGeneration = assetGeneration;
        S.loadingRenderId = String(render.id);
        S.isLoadingBuffers = true;
        S.trackState = defaultStateForTracks(render.tracks);
        S.leadTrack = render.tracks.find((track) => track !== 'original') || render.tracks[0] || null;
        S.auditionTrack = null;
        S.masterTime = 0;
        S.duration = 0;

        if (persist) {
            saveRenderCache(render);
        }

        setPlayerVisibility(true);
        updateLatestHeader(render);
        updateMasterControls();
        updateMasterTime(0, 0);

        requestAnimationFrame(() => {
            bindTrackButtons();
            updateTrackButtonStates();

            (async () => {
                try {
                    const loadedTracks = await Promise.all(
                        (render.tracks || []).map(async (track) => {
                            const url = render.stems?.[track] || null;
                            if (!url) return null;

                            const media = await createMedia(track, url);
                            return { track, media };
                        })
                    );

                    if (S.assetGeneration !== assetGeneration || String(S.currentRender?.id || '') !== String(render.id)) {
                        loadedTracks.forEach((item) => {
                            if (item?.media?.waveUrl) {
                                try { URL.revokeObjectURL(item.media.waveUrl); } catch (_) {}
                            }
                        });
                        return;
                    }

                    loadedTracks.forEach((item) => {
                        if (!item?.media) return;

                        S.medias[item.track] = item.media;
                        S.duration = Math.max(S.duration, Number(item.media.duration || 0));
                    });

                    Object.entries(S.medias).forEach(([track, media]) => {
                        const wave = createWave(track, media.waveUrl);
                        if (wave) {
                            S.waves[track] = wave;
                        }
                    });

                    applyStemMix();
                    bindTrackButtons();
                    updateTrackButtonStates();
                    syncAllWaveProgress(0);
                    updateMasterTime(0, S.duration);
                } catch (error) {
                    console.warn('[STEM] Failed to prepare track assets', error);
                } finally {
                    if (S.assetGeneration === assetGeneration) {
                        S.isLoadingBuffers = false;
                        S.loadingRenderId = null;
                        updateMasterControls();
                        updateTrackButtonStates();
                    }
                }
            })();
        });
    }

    function clearStemRenderUI() {
        const tracksEl = document.getElementById('stem-tracks');
        if (tracksEl) {
            tracksEl.innerHTML = '';
        }

        destroyPlayers();
        setPlayerVisibility(false);
        updateLatestHeader(null);
        updateMasterControls();
        updateMasterTime(0, 0);
    }

    function highlightLatestRender() {
        const firstCard = document.querySelector('.stem-render-item');
        if (!firstCard) return;

        firstCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        firstCard.style.transition = 'box-shadow 0.3s ease';
        firstCard.style.boxShadow = '0 0 0 3px var(--bs-success, #198754)';

        setTimeout(() => {
            firstCard.style.boxShadow = '';
        }, 2500);
    }

    function syncRenderFromServerDom(options = {}) {
        const { allowCache = false } = options;
        const serverRender = readInitialRender();
        const serverId = String(serverRender?.id || '');
        const currentId = String(S.currentRender?.id || '');

        if (serverId) {
            if (currentId !== serverId && S.loadingRenderId !== serverId) {
                loadStemRender(serverRender, { persist: false });
                return;
            }

            if (currentId === serverId) {
                S.currentRender = { ...S.currentRender, ...serverRender };
            } else {
                S.currentRender = serverRender;
            }

            updateLatestHeader(S.currentRender);
            setPlayerVisibility(true);
            updateMasterControls();

            if (Object.keys(S.medias).length === 0 && S.loadingRenderId !== serverId) {
                loadStemRender(S.currentRender, { persist: false });
            }

            return;
        }

        if (allowCache) {
            const latestReady = document.getElementById('stem-latest-ready')?.value === '1';
            const cachedRender = latestReady ? loadLatestCachedRender() : null;

            if (cachedRender && String(cachedRender.id || '') !== currentId) {
                loadStemRender(cachedRender, { persist: false });
                return;
            }
        }

        if (currentId) {
            clearStemRenderUI();
            return;
        }

        updateLatestHeader(null);
        setPlayerVisibility(false);
        updateMasterControls();
        updateMasterTime(0, 0);
    }

    function registerLivewireEvents() {
        if (!window.Livewire || S.eventsBound) return;
        S.eventsBound = true;

        Livewire.on('stem-render-loaded', (event) => {
            const render = event?.render || null;
            if (render) {
                loadStemRender(render);
            }
        });

        Livewire.on('stem-render-cleared', () => {
            clearStemRenderUI();
        });

        Livewire.on('stem-job-started', (data) => {
            spaSave(data);
        });

        Livewire.on('stem-job-state-sync', (data) => {
            const saved = spaLoad();
            if (saved && saved.jobId === data.jobId) {
                spaSave({ ...saved, ...data });
            }
        });

        Livewire.on('stem-job-state-clear', () => {
            spaClear();
        });

        Livewire.on('stem-job-completed', (event) => {
            spaClear();

            const render = event?.render || null;
            if (render) {
                loadStemRender(render);
            }

            requestAnimationFrame(() => {
                highlightLatestRender();
            });
        });

        if (!S.commitHooked && typeof Livewire.hook === 'function') {
            S.commitHooked = true;

            Livewire.hook('commit', ({ succeed }) => {
                succeed(() => {
                    requestAnimationFrame(() => {
                        syncRenderFromServerDom();
                        bindTrackButtons();
                        bindWaveSeekHandlers();
                        updateTrackButtonStates();
                        updateMasterControls();
                    });
                });
            });
        }
    }

    function bootStemRenderPage() {
        if (S.bootTimer) {
            clearTimeout(S.bootTimer);
        }

        S.bootTimer = setTimeout(() => {
            S.bootTimer = null;

            registerLivewireEvents();
            spaRestoreIfNeeded();
            syncRenderFromServerDom({ allowCache: true });
        }, 0);
    }

    if (!S.listenersBound) {
        S.listenersBound = true;

        document.addEventListener('livewire:initialized', bootStemRenderPage);
        document.addEventListener('livewire:navigated', bootStemRenderPage);
        document.addEventListener('livewire:navigating', destroyPlayers);
    }

    bootStemRenderPage();
})();
</script>
@endpush
