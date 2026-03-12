<?php

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
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
#[Title('STEM Separation | METKURD')]
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
        $this->hydrateLatestFinishedRender();
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
        $this->hydrateLatestFinishedRender();
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

    protected function hydrateLatestFinishedRender(): void
    {
        $customerId = auth('app')->id();
        if (!$customerId) {
            $this->latestFinishedJobId = null;
            return;
        }

        $job = MlJob::query()
            ->where('customer_id', $customerId)
            ->where('job_kind', $this->jobKind)
            ->where('status', 'done')
            ->orderByDesc('finished_at')
            ->first();

        $this->latestFinishedJobId = $job ? (string) $job->id : null;
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
            $this->dispatch('alert', type: 'success', message: 'Audio uploaded successfully.');
        } catch (\Throwable $e) {
            Log::error('STEM_AUDIO_UPLOAD_FAIL', [
                'message' => $e->getMessage(),
            ]);

            $this->resetAudioState();
            $this->dispatch('alert', type: 'error', message: 'Failed to inspect the uploaded audio file.');
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
            return 'You must be logged in.';
        }

        if ($this->currentJobId && !$this->jobFinished) {
            return 'A stem separation job is already in progress.';
        }

        if (method_exists($customer, 'isAllowed') && !$customer->isAllowed($this->fullActionCode())) {
            return 'Your plan does not allow this STEM separation mode.';
        }

        if (!$this->audioFile) {
            return 'Please upload an audio file.';
        }

        if ($this->audioDurationSec === null || $this->audioDurationSec <= 0) {
            return 'Audio duration could not be detected.';
        }

        if ($this->creditsCost <= 0) {
            return 'Pricing could not be calculated.';
        }

        if ($this->walletBalance < $this->creditsCost) {
            return 'Not enough credits.';
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
            'input_name' => (string) data_get($job->input, 'audio_name', 'Untitled audio'),
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
            $this->dispatch('alert', type: 'warning', message: 'A stem separation job is already in progress.');
            return;
        }

        $customer = auth('app')->user();
        if (!$customer) {
            $this->dispatch('alert', type: 'error', message: 'You must be logged in.');
            return;
        }

        if (method_exists($customer, 'isAllowed') && !$customer->isAllowed($this->fullActionCode())) {
            $this->dispatch('alert', type: 'error', message: 'Your plan does not allow this STEM separation mode.');
            return;
        }

        $this->validate();

        if ($this->audioDurationSec === null || $this->audioDurationSec <= 0) {
            $this->dispatch('alert', type: 'error', message: 'Audio duration could not be detected.');
            return;
        }

        $needed = $this->requiredCredits();
        if ($needed <= 0) {
            $this->dispatch('alert', type: 'error', message: 'Pricing is not configured for STEM separation.');
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
                $this->dispatch('alert', type: 'error', message: 'Not enough credits.');
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
                throw new \RuntimeException((string) ($lock['message'] ?? 'Could not lock the STEM job.'));
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
                throw new \RuntimeException('RUNPOD_ENDPOINT_ID_STEM is missing.');
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
            $this->dispatch('alert', type: 'success', message: 'Stem separation job submitted.');
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
            $this->dispatch('alert', type: 'success', message: (string) ($result['message'] ?? 'Stem separation completed.'));
            $this->resetJobState();
            $this->hydrateLatestFinishedRender();

            return;
        }

        if (!empty($result['failed'])) {
            $this->dispatch('stem-job-state-clear');
            $this->dispatch('header:refresh');
            $this->dispatch('stem-renders-refresh');
            $this->dispatch('alert', type: 'error', message: (string) ($result['message'] ?? 'Stem separation failed.'));
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
            $this->dispatch('alert', type: 'error', message: 'Render not found.');
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
            $this->dispatch('alert', type: 'error', message: 'Render not found.');
            return;
        }

        try {
            $storage->deleteStemOutputs($job);

            if (($this->loadedRender['id'] ?? null) === (string) $job->id) {
                $this->loadedRender = null;
                $this->dispatch('stem-render-cleared');
            }

            $this->resetPage();
            $this->hydrateLatestFinishedRender();
            $this->dispatch('customerStorageUpdated');
            $this->dispatch('header:refresh');
            $this->dispatch('stem-renders-refresh');
            $this->dispatch('alert', type: 'success', message: 'Render deleted.');
        } catch (\Throwable $e) {
            Log::error('STEM_DELETE_RENDER_FAIL', [
                'job_id' => (string) $job->id,
                'message' => $e->getMessage(),
            ]);

            $this->dispatch('alert', type: 'error', message: 'Failed to delete render.');
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
        $this->dispatch('alert', type: 'warning', message: 'Current STEM job eliminated. Credits were not refunded.');
        $this->resetJobState();
    }

};
?>

<div
    id="stem-page-root"
    x-data="stemFormCache()"
    x-init="init()"
>
    @if($currentJobId && !$jobFinished)
        <div wire:poll.keep-alive.3000ms="pollJob"></div>
    @endif

    @php
        $loadedTracks = $loadedRender['tracks'] ?? [];
    @endphp

    <div class="container-fluid py-3">
        <div class="row g-3">
            <div class="col-xl-7">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-transparent border-0 pb-0">
                        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                            <div>
                                <h4 class="mb-1">Stem Separation</h4>
                                <p class="text-muted mb-0 small">
                                    Upload one audio file and split it into 2 or 4 stems.
                                </p>
                            </div>

                            <div class="d-flex gap-2 flex-wrap text-end small">
                                <div class="mini-stat">
                                    <div class="text-muted">Wallet</div>
                                    <div class="fw-semibold">{{ number_format($walletBalance) }}</div>
                                </div>

                                <div class="mini-stat">
                                    <div class="text-muted">Cost</div>
                                    <div class="fw-semibold">{{ number_format($creditsCost) }}</div>
                                </div>

                                @if($audioDurationMin)
                                    <div class="mini-stat">
                                        <div class="text-muted">Minutes</div>
                                        <div class="fw-semibold">{{ number_format((float) $audioDurationMin, 2) }}</div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Input Audio</label>
                            <div wire:ignore>
                                <input type="file" id="stem-audio-pond">
                            </div>

                            <div wire:loading wire:target="audioFile" class="small text-primary mt-2">
                                Uploading audio...
                            </div>

                            @if($audioFileName)
                                <div class="alert alert-light border mt-3 mb-0">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                        <div>
                                            <div class="fw-semibold">{{ $audioFileName }}</div>
                                            <div class="small text-muted">
                                                {{ $audioDurationSec ? number_format($audioDurationSec, 2) . ' sec' : 'Unknown duration' }}
                                                @if($audioFileBytes)
                                                    {{ number_format($audioFileBytes / 1024 / 1024, 2) }} MB
                                                @endif
                                            </div>
                                        </div>

                                        <button class="btn btn-outline-danger btn-sm" wire:click="removeAudioFile">
                                            Remove
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>

                        <div class="card border-0 bg-body-tertiary">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                    <div>
                                        <h6 class="mb-1">Multi-Track Player</h6>
                                        <div class="small text-muted">
                                            Master play/stop with individual Solo, Mute, and Download controls.
                                        </div>
                                    </div>

                                    <div class="d-flex gap-2 flex-wrap">
                                        <button id="stem-master-play" type="button" class="btn btn-primary btn-sm" disabled>
                                            Play
                                        </button>

                                        <button id="stem-master-stop" type="button" class="btn btn-outline-secondary btn-sm" disabled>
                                            Stop
                                        </button>

                                        <a
                                            id="stem-download-all"
                                            href="{{ $loadedRender['downloads']['all'] ?? '#' }}"
                                            class="btn btn-outline-dark btn-sm {{ isset($loadedRender['downloads']['all']) ? '' : 'disabled' }}"
                                        >
                                            Download All
                                        </a>
                                    </div>
                                </div>

                                <div id="stem-empty-state" class="{{ $loadedRender ? 'd-none' : '' }}">
                                    <div class="text-center py-5 border rounded-3 bg-white">
                                        <div class="fw-semibold mb-1">No render loaded yet</div>
                                        <div class="small text-muted">
                                            Run a job or load one from Recent Renders.
                                        </div>
                                    </div>
                                </div>

                                <div id="stem-tracks-wrapper" wire:ignore class="{{ $loadedRender ? '' : 'd-none' }}">
                                    <div id="stem-tracks" class="d-flex flex-column gap-3">
                                        @foreach($loadedTracks as $track)
                                            @php
                                                $trackLabel = match ($track) {
                                                    'original' => 'Original',
                                                    'vocals' => 'Vocals',
                                                    'instrumental' => 'Instrumental',
                                                    'drums' => 'Drums',
                                                    'bass' => 'Bass',
                                                    'other' => 'Other',
                                                    default => ucfirst($track),
                                                };
                                            @endphp

                                            <div class="border rounded-3 p-3 stem-track-row" data-track="{{ $track }}">
                                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                                    <div>
                                                        <div class="fw-semibold">{{ $trackLabel }}</div>
                                                        <div class="small text-muted">
                                                            {{ $track === 'original' ? 'Original uploaded audio — muted by default' : 'Separated output track' }}
                                                        </div>
                                                    </div>

                                                    <div class="d-flex gap-2 flex-wrap">
                                                        <button type="button" class="btn btn-outline-primary btn-xs track-solo" data-track="{{ $track }}">
                                                            Solo
                                                        </button>

                                                        <button type="button" class="btn {{ $track === 'original' ? 'btn-warning' : 'btn-outline-warning' }} btn-xs track-mute" data-track="{{ $track }}">
                                                            {{ $track === 'original' ? 'Muted' : 'Mute' }}
                                                        </button>

                                                        <a
                                                            href="{{ $loadedRender['downloads'][$track] ?? '#' }}"
                                                            class="btn btn-outline-success btn-xs {{ isset($loadedRender['downloads'][$track]) ? '' : 'disabled' }}"
                                                        >
                                                            Download
                                                        </a>
                                                    </div>
                                                </div>

                                                <div id="wave-{{ $track }}" class="stem-wave"></div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="small text-muted mt-3">
                                    Recent renders and active job state are cached in browser storage for instant reload.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-5">
                <div class="card shadow-sm border-0 mb-3">
                    <div class="card-header bg-transparent border-0 pb-0">
                        <h5 class="mb-1">Parameters</h5>
                        <p class="text-muted small mb-0">
                            Configure separation mode and output format.
                        </p>
                    </div>

                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Separation Mode</label>
                            <select class="form-select" wire:model.live="stems">
                                <option value="2">2 Stems</option>
                                <option value="4">4 Stems</option>
                            </select>
                            <div class="small text-muted mt-1">
                                4-stem separation costs more than 2-stem.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Model</label>
                            <select class="form-select" wire:model.live="model">
                                <option value="htdemucs_ft">htdemucs_ft</option>
                            </select>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Stem Codec</label>
                                <select class="form-select" wire:model.live="stemCodec">
                                    <option value="mp3">mp3</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Stem Bitrate</label>
                                <select class="form-select" wire:model.live="stemBitrate">
                                    <option value="192k">192k</option>
                                </select>
                            </div>
                        </div>

                        <div class="wasr-cost-preview rounded-3 p-3 mt-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-semibold">Estimated Cost</div>
                                    <div class="small text-muted">
                                        {{ $stems === 4 ? '4-stem separation pricing' : '2-stem separation pricing' }}
                                    </div>
                                </div>

                                <div class="badge wasr-badge-credits px-3 py-2">
                                    {{ number_format($creditsCost) }} credits
                                </div>
                            </div>
                        </div>

                        <div class="d-grid gap-2 mt-3">
                            <button
                                class="btn {{ $this->canSeparate ? 'btn-primary' : 'btn-danger' }}"
                                wire:click="submit"
                                wire:loading.attr="disabled"
                                wire:target="submit,audioFile"
                                @disabled(!$this->canSeparate)
                                type="button"
                            >
                                <span wire:loading.remove wire:target="submit,audioFile">
                                    {{ $this->canSeparate ? 'Separate Audio' : ($this->separateBlockedReason ?? 'Separate Audio') }}
                                </span>

                                <span wire:loading wire:target="audioFile">
                                    <span class="spinner-border spinner-border-sm me-1"></span>
                                    Uploading audio...
                                </span>

                                <span wire:loading wire:target="submit">
                                    <span class="spinner-border spinner-border-sm me-1"></span>
                                    Starting...
                                </span>
                            </button>

                            <button class="btn btn-light" wire:click="resetForm" type="button">
                                Reset
                            </button>

                            @if($currentJobId && !$jobFinished)
                                <button type="button" class="btn btn-outline-danger btn-sm" wire:click="openEliminateModal">
                                    Eliminate Current Job
                                </button>
                            @endif

                            @if($walletBalance < $creditsCost && $creditsCost > 0)
                                <span class="small text-danger">
                                    Not enough credits for this separation.
                                </span>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0">
                    <div class="card-header bg-transparent border-0 pb-0">
                        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                            <div>
                                <h5 class="mb-1">Recent Renders</h5>
                                <p class="text-muted small mb-0">Load, stream, download, or delete previous results.</p>
                            </div>

                            <div style="min-width: 220px;">
                                <input
                                    type="text"
                                    class="form-control form-control-sm"
                                    placeholder="Search..."
                                    wire:model.live.debounce.300ms="search"
                                >
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        @forelse($this->recentRenders as $render)
                            <div class="border rounded-3 p-3 mb-3 stem-render-item {{ $latestFinishedJobId === (string) $render['id'] ? 'stem-render-item--latest' : '' }}">
                                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                    <div>
                                        <div class="fw-semibold">{{ $render['input_name'] ?: 'Untitled audio' }}</div>
                                        <div class="small text-muted">
                                            {{ $render['mode'] }}-Stem - {{ $render['created_at_human'] ?: 'Just now' }}
                                        </div>
                                        <div class="small text-muted">
                                            Job: {{ $render['id'] }}
                                        </div>
                                    </div>

                                    <div class="d-flex gap-2 flex-wrap">
                                        <button type="button" class="btn btn-outline-primary btn-xs" wire:click="loadRender('{{ $render['id'] }}')">
                                            Load
                                        </button>

                                        <a href="{{ $render['downloads']['all'] }}" class="btn btn-outline-dark btn-xs">
                                            ZIP
                                        </a>

                                        <button type="button" class="btn btn-outline-danger btn-xs" wire:click="deleteRender('{{ $render['id'] }}')">
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="text-muted small">No stem renders yet.</div>
                        @endforelse

                        @if($this->recentRenders->hasPages())
                            <div class="mt-3">
                                {{ $this->recentRenders->links() }}
                            </div>
                        @endif
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
                        <h5 class="modal-title">Eliminate current STEM job?</h5>
                        <button type="button" class="btn-close" wire:click="closeEliminateModal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2">
                            This will stop tracking the current STEM job and mark it as eliminated.
                        </p>
                        <p class="mb-0 text-danger small">
                            Credits are not refundable.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" wire:click="closeEliminateModal">Cancel</button>
                        <button type="button" class="btn btn-danger" wire:click="eliminateCurrentJob">
                            Eliminate
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

@push('styles')
<link href="https://unpkg.com/filepond@^4/dist/filepond.min.css" rel="stylesheet">
<link href="https://unpkg.com/filepond-plugin-file-validate-type/dist/filepond-plugin-file-validate-type.min.css" rel="stylesheet">
<style>
    .wasr-cost-preview{
        background: rgba(var(--bs-warning-rgb), .08);
        border: 1px solid rgba(var(--bs-warning-rgb), .22);
    }

    .wasr-badge-credits{
        background: rgba(var(--bs-warning-rgb), .18);
        color: var(--bs-warning-text-emphasis);
        border: 1px solid rgba(var(--bs-warning-rgb), .24);
    }

    .btn-xs{
        padding: .2rem .4rem;
        font-size: .72rem;
        border-radius: .3rem;
    }

    .stem-track-row{
        background: rgba(255,255,255,.65);
    }

    .stem-wave{
        min-height: 88px;
        background: rgba(0,0,0,.03);
        border: 1px solid rgba(0,0,0,.08);
        border-radius: .75rem;
    }

    .stem-render-item{
        transition: background .15s ease;
    }

    .stem-render-item:hover{
        background: rgba(var(--bs-primary-rgb), .03);
    }

    .stem-render-item--latest{
        border-left: 3px solid var(--bs-primary);
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
        };
    }

    const S = window.__STEM_POND__;

    FilePond.registerPlugin(
        FilePondPluginFileValidateType,
        FilePondPluginFileValidateSize
    );

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
        if (S.pond) {
            try { S.pond.destroy(); } catch (_) {}
            S.pond = null;
        }
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
                    <div class="mb-1"><strong>Drag & Drop</strong> your audio file here</div>
                    <div class="small text-muted">or <span class="filepond--label-action">Browse</span></div>
                </div>
            `,
            server: {
                process: (fieldName, file, metadata, load, error, progress, abort) => {
                    lw.upload(
                        'audioFile',
                        file,
                        () => load(file.name),
                        (e) => error(typeof e === 'string' ? e : 'Upload failed'),
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
        setTimeout(() => bootPond(), 0);
    }

    document.addEventListener('livewire:initialized', bootStemFilePondPage);
    document.addEventListener('livewire:navigated', bootStemFilePondPage);
    document.addEventListener('livewire:navigating', destroyPond);

    if (window.Livewire) {
        Livewire.on('stem-audio-file-cleared', () => {
            if (S.pond) {
                try { S.pond.removeFiles(); } catch (_) {}
            }
        });

        Livewire.on('stem-form-reset', () => {
            if (S.pond) {
                try { S.pond.removeFiles(); } catch (_) {}
            }
        });

        if (typeof Livewire.hook === 'function') {
            Livewire.hook('commit', ({ succeed }) => {
                succeed(() => {
                    requestAnimationFrame(() => {
                        const input = document.getElementById('stem-audio-pond');
                        if (input && !S.pond) {
                            bootPond();
                        }
                    });
                });
            });
        }
    }
})();
</script>
@endpush

@push('scripts')
<script>
(function () {
    'use strict';

    const INITIAL_RENDER = @js($loadedRender);

    if (!window.__STEM_RENDER_PAGE__) {
        window.__STEM_RENDER_PAGE__ = {
            players: {},
            trackState: {},
            currentRender: null,
            isSyncSeeking: false,
            eventsBound: false,
            commitHooked: false,
        };
    }

    const S = window.__STEM_RENDER_PAGE__;
    const RENDER_KEY = 'stem_render_cache_v2';
    const LAST_RENDER_KEY = 'stem_last_render_id_v1';
    const JOB_KEY = 'stem_spa_job_v1';
    const RENDER_TTL = 7 * 24 * 60 * 60 * 1000;
    const JOB_TTL = 30 * 60 * 1000;
    const MAX_RENDER_CACHE = 12;

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

    function destroyPlayers() {
        Object.values(S.players).forEach((player) => {
            try { player.destroy(); } catch (_) {}
        });

        S.players = {};
        S.trackState = {};
        S.currentRender = null;
        S.isSyncSeeking = false;
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
            case 'original': return 'Original';
            case 'vocals': return 'Vocals';
            case 'instrumental': return 'Instrumental';
            case 'drums': return 'Drums';
            case 'bass': return 'Bass';
            case 'other': return 'Other';
            default: return track;
        }
    }

    function trackDescription(track) {
        return track === 'original'
            ? 'Original uploaded audio - muted by default'
            : 'Separated output track';
    }

    function ensureTrackRows(render) {
        const tracksEl = document.getElementById('stem-tracks');
        if (!tracksEl) return false;

        tracksEl.innerHTML = (render.tracks || []).map((track) => {
            const downloadUrl = render.downloads?.[track] || '#';
            const muted = track === 'original';

            return `
                <div class="border rounded-3 p-3 stem-track-row" data-track="${track}">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                        <div>
                            <div class="fw-semibold">${trackLabel(track)}</div>
                            <div class="small text-muted">${trackDescription(track)}</div>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="button" class="btn btn-outline-primary btn-xs track-solo" data-track="${track}">
                                Solo
                            </button>
                            <button type="button" class="btn ${muted ? 'btn-warning' : 'btn-outline-warning'} btn-xs track-mute" data-track="${track}">
                                ${muted ? 'Muted' : 'Mute'}
                            </button>
                            <a href="${downloadUrl}" class="btn btn-outline-success btn-xs${downloadUrl === '#' ? ' disabled' : ''}">
                                Download
                            </a>
                        </div>
                    </div>
                    <div id="wave-${track}" class="stem-wave"></div>
                </div>
            `;
        }).join('');

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
            const player = S.players[track];
            if (!player) return;

            let volume = 1;
            if (soloed.length > 0) {
                volume = soloed.includes(track) ? 1 : 0;
            } else if (S.trackState[track]?.mute) {
                volume = 0;
            }

            S.trackState[track].volume = volume;
            player.setVolume(volume);
        });
    }

    function syncAllToTime(seconds, exceptTrack = null) {
        if (S.isSyncSeeking) return;
        S.isSyncSeeking = true;

        Object.entries(S.players).forEach(([track, player]) => {
            if (track === exceptTrack) return;

            try {
                const duration = player.getDuration() || 1;
                player.seekTo(seconds / duration);
            } catch (_) {}
        });

        setTimeout(() => { S.isSyncSeeking = false; }, 40);
    }

    function createPlayer(track, url) {
        const container = document.getElementById(`wave-${track}`);
        if (!container || !url) return null;

        const player = WaveSurfer.create({
            container,
            waveColor: '#bfc7d5',
            progressColor: '#4f46e5',
            cursorColor: '#111827',
            height: 72,
            normalize: true,
            autoScroll: false,
        });

        player.load(url);

        player.on('seeking', (progress) => {
            if (S.isSyncSeeking) return;
            const duration = player.getDuration() || 1;
            syncAllToTime(progress * duration, track);
        });

        return player;
    }

    function updateTrackButtonStates() {
        document.querySelectorAll('.track-solo').forEach((button) => {
            const track = button.dataset.track;
            const active = !!S.trackState[track]?.solo;

            button.classList.toggle('active', active);
            button.classList.toggle('btn-primary', active);
            button.classList.toggle('btn-outline-primary', !active);
        });

        document.querySelectorAll('.track-mute').forEach((button) => {
            const track = button.dataset.track;
            const muted = !!S.trackState[track]?.mute;

            button.classList.toggle('btn-warning', muted);
            button.classList.toggle('btn-outline-warning', !muted);
            button.textContent = muted ? 'Muted' : 'Mute';
        });
    }

    function bindTrackButtons() {
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
            playBtn.disabled = Object.keys(S.players).length === 0;
            playBtn.onclick = () => {
                Object.values(S.players).forEach((player) => {
                    try { player.play(); } catch (_) {}
                });
            };
        }

        if (stopBtn) {
            stopBtn.disabled = Object.keys(S.players).length === 0;
            stopBtn.onclick = () => {
                Object.values(S.players).forEach((player) => {
                    try {
                        player.pause();
                        player.seekTo(0);
                    } catch (_) {}
                });
            };
        }
    }

    function loadStemRender(render, options = {}) {
        if (!render || !render.id || !Array.isArray(render.tracks)) return;

        const { persist = true } = options;
        if (!ensureTrackRows(render)) return;

        destroyPlayers();
        S.currentRender = render;
        S.trackState = defaultStateForTracks(render.tracks);

        if (persist) {
            saveRenderCache(render);
        }

        setPlayerVisibility(true);

        const downloadAll = document.getElementById('stem-download-all');
        if (downloadAll && render.downloads?.all) {
            downloadAll.href = render.downloads.all;
            downloadAll.classList.remove('disabled');
        }

        requestAnimationFrame(() => {
            (render.tracks || []).forEach((track) => {
                const url = render.stems?.[track] || null;
                if (url) {
                    S.players[track] = createPlayer(track, url);
                }
            });

            setTimeout(() => {
                applyStemMix();
                bindTrackButtons();
                updateTrackButtonStates();
            }, 200);
        });
    }

    function clearStemRenderUI() {
        const tracksEl = document.getElementById('stem-tracks');
        if (tracksEl) {
            tracksEl.innerHTML = '';
        }

        destroyPlayers();
        setPlayerVisibility(false);
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
                        if (S.currentRender && Object.keys(S.players).length === 0) {
                            loadStemRender(S.currentRender, { persist: false });
                            return;
                        }

                        bindTrackButtons();
                    });
                });
            });
        }
    }

    function bootStemRenderPage() {
        const runBoot = () => {
            registerLivewireEvents();
            spaRestoreIfNeeded();

            if (INITIAL_RENDER && INITIAL_RENDER.id) {
                loadStemRender(INITIAL_RENDER, { persist: false });
                return;
            }

            const cachedRender = loadLatestCachedRender();
            if (cachedRender) {
                loadStemRender(cachedRender, { persist: false });
                return;
            }

            setPlayerVisibility(!!document.querySelector('#stem-tracks .stem-track-row'));
        };

        setTimeout(runBoot, 0);
    }

    document.addEventListener('livewire:initialized', bootStemRenderPage);
    document.addEventListener('livewire:navigated', bootStemRenderPage);
    document.addEventListener('livewire:navigating', destroyPlayers);
})();
</script>
@endpush

@push('scripts')
<script>
function stemFormCache() {
    return {
        cacheKey: 'stem_form_state_v3',

        stems: @entangle('stems').live,
        model: @entangle('model').live,
        stemCodec: @entangle('stemCodec').live,
        stemBitrate: @entangle('stemBitrate').live,

        init() {
            this.restore();

            this.$watch('stems', () => this.save());
            this.$watch('model', () => this.save());
            this.$watch('stemCodec', () => this.save());
            this.$watch('stemBitrate', () => this.save());

            window.addEventListener('beforeunload', () => this.save());
            window.addEventListener('stem-form-state-clear', () => this.clear());
        },

        normalizeStems(value) {
            return parseInt(value, 10) === 2 ? 2 : 4;
        },

        save() {
            try {
                localStorage.setItem(this.cacheKey, JSON.stringify({
                    stems: this.normalizeStems(this.stems),
                    model: this.model ?? 'htdemucs_ft',
                    stemCodec: this.stemCodec ?? 'mp3',
                    stemBitrate: this.stemBitrate ?? '192k',
                    ts: Date.now(),
                }));
            } catch (_) {}
        },

        restore() {
            try {
                const raw = localStorage.getItem(this.cacheKey);
                if (!raw) return;

                const data = JSON.parse(raw);
                if (!data) return;

                if (Date.now() - (data.ts || 0) > 7 * 24 * 60 * 60 * 1000) {
                    localStorage.removeItem(this.cacheKey);
                    return;
                }

                if (data.stems !== undefined) this.stems = this.normalizeStems(data.stems);
                if (data.model !== undefined) this.model = data.model;
                if (data.stemCodec !== undefined) this.stemCodec = data.stemCodec;
                if (data.stemBitrate !== undefined) this.stemBitrate = data.stemBitrate;
            } catch (_) {}
        },

        clear() {
            try {
                localStorage.removeItem(this.cacheKey);
            } catch (_) {}
        }
    };
}
</script>
@endpush
