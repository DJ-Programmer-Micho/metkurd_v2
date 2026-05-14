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
use App\Support\AppRenderPayloads;

new
#[Layout('app::layouts.app')]
class extends Component
{
    use WithPagination;
    use WithFileUploads;
    use \App\Support\Plans\ResolvesConcurrentJobLimit;

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
    public ?string $selectedRenderId = null;
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
            'audioFile' => 'required|file|mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,audio/aac,audio/ogg,audio/webm,audio/flac,audio/x-flac|max:' . $this->stemMaxUploadKb(),
            'stems' => 'required|integer|in:2,4',
            'model' => 'required|string|max:100',
            'stemCodec' => 'required|string|in:mp3',
            'stemBitrate' => 'required|string|in:192k',
        ];
    }

    protected function messages(): array
    {
        return [
            'audioFile.max' => __('Maximum file size is :size MB.', ['size' => $this->stemMaxUploadMb()]),
        ];
    }

    protected function stemMaxUploadKb(): int
    {
        return max(1, (int) config('livewire.stem_max_upload_kb', 102400));
    }

    protected function stemMaxUploadMb(): int
    {
        return max(1, (int) ceil($this->stemMaxUploadKb() / 1024));
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
                return array_merge(AppRenderPayloads::stemSummary($job), ['is_latest' => $index === 0]);
            })
        );

        return $paginator;
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

    protected function releaseTemporaryAudioUpload(): void
    {
        $upload = $this->audioFile;
        $this->audioFile = null;

        if (!is_object($upload) || !method_exists($upload, 'delete')) {
            return;
        }

        try {
            $upload->delete();
        } catch (\Throwable $e) {
            Log::warning('STEM_TMP_UPLOAD_CLEANUP_FAIL', [
                'message' => $e->getMessage(),
            ]);
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
            $this->selectedRenderId = null;

            if ($dispatchBrowserEvent) {
                $this->dispatch('stem-render-cleared');
            }

            return;
        }

        $latestJob = $this->latestFinishedStemJob();
        $this->latestFinishedJobId = $latestJob ? (string) $latestJob->id : null;

        $selectedId = (string) ($this->selectedRenderId ?? '');
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

        $this->selectedRenderId = null;

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

    protected function currentActiveJobsCount(): int
    {
        $customerId = auth('app')->id();

        if (! $customerId) {
            return 0;
        }

        return MlJob::query()
            ->where('customer_id', $customerId)
            ->where('job_kind', $this->jobKind)
            ->whereIn('status', ['queued', 'running', 'saving'])
            ->whereNotNull('lock_expires_at')
            ->where('lock_expires_at', '>', now())
            ->count();
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

        if ($this->currentActiveJobsCount() >= $this->allowedConcurrentJobs()) {
            return __('You reached your concurrent job limit for the current plan.');
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

    protected function setLoadedRenderFromJob(MlJob $job, bool $dispatchBrowserEvent = true): void
    {
        $jobId = (string) $job->id;
        $this->selectedRenderId = $jobId;

        if ($dispatchBrowserEvent) {
            $this->dispatch('stem-render-selected', jobId: $jobId);
        }
    }

    #[Computed]
    public function selectedRenderSummary(): ?array
    {
        $jobId = (string) ($this->selectedRenderId ?: $this->latestFinishedJobId ?: '');

        if ($jobId === '') {
            return null;
        }

        $job = $this->finishedStemJobById($jobId);

        return $job ? AppRenderPayloads::stemSummary($job) : null;
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

        if ($this->currentActiveJobsCount() >= $this->allowedConcurrentJobs()) {
            $this->dispatch('alert', type: 'warning', message: __('You reached your concurrent job limit for the current plan.'));
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

            $customer->loadMissing('profile');
            $audioExt = strtolower((string) ($this->audioExt ?: $this->audioFile?->getClientOriginalExtension() ?: 'wav'));
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

            $savedAudio = $storage->storeJobInputFile(
                customer: $customer,
                file: $this->audioFile,
                toolCode: $this->toolCode,
                jobId: $jobId,
                meta: [
                    'job_id' => $jobId,
                    'tool' => 'stem',
                    'purpose' => 'input_audio',
                    'role' => 'source_audio',
                    'checksum' => $this->audioHash,
                    'original_name' => $this->audioFileName,
                ],
                extension: $audioExt,
                baseName: 'input'
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

            $this->releaseTemporaryAudioUpload();

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
                $this->setLoadedRenderFromJob($fresh, false);
                $this->dispatch('stem-job-completed', jobId: (string) $fresh->id);
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

            $deletedLoadedRender = ($this->selectedRenderId === (string) $job->id);
            if ($deletedLoadedRender) {
                $this->selectedRenderId = null;
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
        <div wire:poll.visible.7000ms="pollJob"></div>
    @endif

    @php
        $selectedRenderSummary = $this->selectedRenderSummary();
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

        $latestTitle = $selectedRenderSummary['input_name'] ?? null;
        $latestTitle = $latestTitle ?: ($selectedRenderSummary['id'] ?? '-');

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
        $gpuQueueMessage = __('MetKurd AI GPUs are currently busy. Your job is queued and will start automatically as soon as capacity is available.');
        $gpuQueueMessageDir = in_array(app()->getLocale(), ['ar', 'ku']) ? 'rtl' : 'ltr';
        $stemMaxUploadKb = max(1, (int) config('livewire.stem_max_upload_kb', 102400));
        $stemMaxUploadMb = max(1, (int) ceil($stemMaxUploadKb / 1024));
        $stemMaxUploadBytes = $stemMaxUploadKb * 1024;
        $stemRenderI18n = [
            'preparing' => __('Preparing...'),
            'playAll' => __('Play All'),
            'pauseAll' => __('Pause All'),
            'play' => __('Play'),
            'pause' => __('Pause'),
            'loading' => __('Loading'),
            'muted' => __('Muted'),
            'mute' => __('Mute'),
            'original' => __('Original'),
            'vocals' => __('Vocals'),
            'instrumental' => __('Instrumental'),
            'drums' => __('Drums'),
            'bass' => __('Bass'),
            'other' => __('Other'),
            'originalDescription' => __('Original uploaded audio - muted by default'),
            'separatedTrack' => __('Separated output track'),
            'seekAll' => __('Click to seek all stems together'),
            'clickPlayToLoad' => __('Click play to load the audio preview'),
            'loadingTrack' => __('Loading track...'),
            'previewUnavailable' => __('Preview unavailable'),
        ];
    @endphp

    <script type="application/json" id="stem-render-i18n">@json($stemRenderI18n)</script>

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

                        @if($status === 'queued')
                            <div dir="{{ $gpuQueueMessageDir }}" class="text-danger mt-2" style="font-size: 20px">{{ $gpuQueueMessage }}</div>
                        @endif
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
                                    {{ __('WAV recommended | Max :size MB', ['size' => $stemMaxUploadMb]) }}
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
                                    @if($selectedRenderSummary && isset($selectedRenderSummary['downloads']['all']))
                                        <small id="stem-current-render-label" class="text-muted mt-2 mt-md-0 mr-2">
                                            {{ __('Render:') }} <b id="stem-current-render-id">#{{ $selectedRenderSummary['id'] }}</b>
                                        </small>
                                        <a
                                            id="stem-download-all"
                                            href="{{ $selectedRenderSummary['downloads']['all'] ?? '#' }}"
                                            class="btn btn-sm btn-primary {{ isset($selectedRenderSummary['downloads']['all']) ? '' : 'disabled' }}"
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

                            <input type="hidden" id="stem-latest-ready" value="{{ $selectedRenderSummary ? 1 : 0 }}">
                            <input type="hidden" id="stem-selected-render-id" value="{{ $selectedRenderSummary['id'] ?? '' }}">
                            <input
                                type="hidden"
                                id="stem-render-payload-url-template"
                                value="{{ route('app.renders.stem.payload', ['locale' => app()->getLocale(), 'jobId' => '__JOB_ID__']) }}"
                            >

                            <div id="stem-empty-state" class="{{ $selectedRenderSummary ? 'd-none' : '' }}">
                                <div class="text-muted small text-center py-4">{{ __('No output selected yet.') }}</div>
                            </div>

                            <div id="stem-tracks-wrapper" wire:ignore class="{{ $selectedRenderSummary ? '' : 'd-none' }}">
                                <hr class="mt-3">

                                <div class="stem-player-container">
                                    <div class="master-controls mb-3">
                                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                            <div class="d-flex align-items-center gap-2">
                                                <button id="stem-master-play" type="button" class="btn btn-lg btn-success btn-master-play" disabled>
                                                    <i class="mdi mdi-play"></i> {{ __('Play All') }}
                                                </button>
                                                <button id="stem-master-stop" type="button" class="btn btn-lg btn-outline-secondary btn-master-stop" disabled>
                                                    <i class="mdi mdi-stop"></i> {{ __('Stop') }}
                                                </button>
                                            </div>
                                            <div class="master-time text-muted">
                                                <span id="master-current">00:00</span> / <span id="master-duration">00:00</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div id="stem-tracks" class="d-flex flex-column gap-3"></div>
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
                                    wire:model.live.debounce.500ms="search"
                                >
                            </div>

                            @if($this->recentRenders->count() === 0)
                                <div class="text-muted small text-center py-4">{{ __('No renders yet.') }}</div>
                            @else
                                <div class="list-group">
                                    @foreach($this->recentRenders as $render)
                                        <div
                                            class="list-group-item render-item stem-render-item {{ $latestFinishedJobId === (string) $render['id'] ? 'stem-render-item--latest' : '' }}"
                                            wire:key="stem-render-{{ $render['id'] }}"
                                        >
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
<link href="{{ asset('app/libs/filepond/filepond.min.css') }}" rel="stylesheet" data-stem-asset="filepond-css">

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

    .stem-wave__placeholder {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: .5rem;
        padding: 0 1rem;
        color: rgba(255,255,255,.65);
        font-size: .85rem;
        text-align: center;
        background: linear-gradient(135deg, rgba(255,255,255,.035), rgba(255,255,255,.015));
    }

    .stem-wave__placeholder .mdi {
        font-size: 1rem;
        opacity: .85;
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
            assetPromises: {},
        };
    }

    const S = window.__STEM_POND__;
    const STEM_FILEPOND_CSS = @js(asset('app/libs/filepond/filepond.min.css'));
    const STEM_FILEPOND_JS = @js(asset('app/libs/filepond/filepond.min.js'));
    const STEM_FILEPOND_SIZE_JS = @js(asset('app/libs/filepond-plugin-file-validate-size/filepond-plugin-file-validate-size.min.js'));
    const STEM_WAVESURFER_JS = 'https://unpkg.com/wavesurfer.js@7/dist/wavesurfer.min.js';
    const STEM_ACCEPT_ATTR = '.wav,.mp3,.m4a,.aac,.ogg,.webm,.flac,audio/*';
    const STEM_MAX_UPLOAD_MB = @js($stemMaxUploadMb);
    const STEM_MAX_UPLOAD_BYTES = @js($stemMaxUploadBytes);
    const STEM_PARAM_I18N = {
        outputs: @js(__('outputs')),
        pricing4: @js(__('4-stem separation pricing')),
        pricing2: @js(__('2-stem separation pricing')),
        dragDrop: @js(__('Drag & Drop')),
        audioHere: @js(__('your audio file here')),
        or: @js(__('or')),
        browse: @js(__('Browse')),
        uploadFailed: @js(__('Upload failed')),
        fileTooLarge: @js(__('Maximum file size is :size MB', ['size' => $stemMaxUploadMb])),
    };
    const FORM_KEY = 'stem_form_state_v3';
    const FORM_TTL = 7 * 24 * 60 * 60 * 1000;
    const PARAM_DEFAULTS = {
        stems: 4,
        model: 'htdemucs_ft',
        stemCodec: 'mp3',
        stemBitrate: '192k',
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

    function dispatchStemAlert(message, type = 'error') {
        const text = String(message || '').trim();
        if (!text) return;

        window.dispatchEvent(new CustomEvent('alert', {
            detail: { type, message: text },
        }));
    }

    function resolveUploadSizeBytes(fileLike) {
        if (!fileLike || typeof fileLike !== 'object') return null;

        const candidates = [
            fileLike.size,
            fileLike.fileSize,
            fileLike?.file?.size,
            fileLike?.source?.size,
        ];

        for (const value of candidates) {
            const parsed = Number(value);
            if (Number.isFinite(parsed) && parsed >= 0) {
                return parsed;
            }
        }

        return null;
    }

    function isUploadTooLargeError(raw) {
        const text = String(raw?.message ?? raw ?? '').toLowerCase();

        return text.includes('413')
            || text.includes('payload too large')
            || text.includes('request entity too large')
            || text.includes('post too large')
            || text.includes('maximum file size')
            || text.includes('file is too large')
            || text.includes('unexpected token <');
    }

    function normalizeUploadErrorMessage(rawError) {
        if (isUploadTooLargeError(rawError)) {
            return STEM_PARAM_I18N.fileTooLarge;
        }

        const text = String(rawError?.message ?? rawError ?? '').trim();
        return text || STEM_PARAM_I18N.uploadFailed;
    }

    function isFileTooLarge(fileLike) {
        const bytes = resolveUploadSizeBytes(fileLike);
        return Number.isFinite(bytes) && bytes > STEM_MAX_UPLOAD_BYTES;
    }

    function ensureStyle(href, key) {
        if (document.querySelector(`link[data-stem-asset="${key}"]`)) {
            return Promise.resolve();
        }

        if (S.assetPromises[key]) {
            return S.assetPromises[key];
        }

        S.assetPromises[key] = new Promise((resolve, reject) => {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;
            link.dataset.stemAsset = key;
            link.onload = () => resolve();
            link.onerror = () => {
                delete S.assetPromises[key];
                reject(new Error(`Failed to load ${key}`));
            };
            document.head.appendChild(link);
        });

        return S.assetPromises[key];
    }

    function ensureScript(src, key, isReady) {
        if (typeof isReady === 'function' && isReady()) {
            return Promise.resolve();
        }

        if (S.assetPromises[key]) {
            return S.assetPromises[key];
        }

        S.assetPromises[key] = new Promise((resolve, reject) => {
            const existing = document.querySelector(`script[data-stem-asset="${key}"]`);
            if (existing) {
                existing.addEventListener('load', () => resolve(), { once: true });
                existing.addEventListener('error', () => reject(new Error(`Failed to load ${key}`)), { once: true });
                return;
            }

            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.dataset.stemAsset = key;
            script.onload = () => resolve();
            script.onerror = () => {
                delete S.assetPromises[key];
                reject(new Error(`Failed to load ${key}`));
            };
            document.head.appendChild(script);
        });

        return S.assetPromises[key];
    }

    async function ensureStemUploadAssets() {
        await ensureStyle(STEM_FILEPOND_CSS, 'filepond-css');
        await ensureScript(STEM_FILEPOND_JS, 'filepond-js', () => !!window.FilePond);
        await ensureScript(STEM_FILEPOND_SIZE_JS, 'filepond-size-js', () => !!window.FilePondPluginFileValidateSize);

        if (!S.pluginsRegistered && window.FilePond) {
            const plugins = [];

            if (window.FilePondPluginFileValidateSize) {
                plugins.push(window.FilePondPluginFileValidateSize);
            }

            if (plugins.length > 0) {
                window.FilePond.registerPlugin(...plugins);
                S.pluginsRegistered = true;
            } else {
                console.warn('[STEM] FilePond plugins are not available.');
            }
        }
    }

    async function ensureStemRenderAssets() {
        await ensureScript(STEM_WAVESURFER_JS, 'wavesurfer-js', () => !!window.WaveSurfer);
    }

    window.__ensureStemRenderAssets = ensureStemRenderAssets;

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

    async function bootPond() {
        const input = document.getElementById('stem-audio-pond');
        if (!input) return;

        destroyPond();

        const lw = getStemComponent();
        if (!lw) return;

        input.setAttribute('accept', STEM_ACCEPT_ATTR);

        try {
            await ensureStemUploadAssets();
        } catch (error) {
            console.warn('[STEM] Failed to load FilePond assets', error);
            return;
        }

        S.pond = FilePond.create(input, {
            allowMultiple: false,
            allowReorder: false,
            allowReplace: true,
            credits: false,
            maxFileSize: `${STEM_MAX_UPLOAD_MB}MB`,
            beforeAddFile: (item) => {
                const file = item?.file || item;
                if (isFileTooLarge(file)) {
                    dispatchStemAlert(STEM_PARAM_I18N.fileTooLarge, 'warning');
                    return false;
                }

                return !!file;
            },
            labelMaxFileSizeExceeded: @js(__('File is too large')),
            labelMaxFileSize: STEM_PARAM_I18N.fileTooLarge,
            labelIdle: `
                <div class="py-3">
                    <div class="mb-1"><strong>${STEM_PARAM_I18N.dragDrop}</strong> ${STEM_PARAM_I18N.audioHere}</div>
                    <div class="small text-muted">${STEM_PARAM_I18N.or} <span class="filepond--label-action">${STEM_PARAM_I18N.browse}</span></div>
                </div>
            `,
            server: {
                process: (fieldName, file, metadata, load, error, progress, abort) => {
                    if (isFileTooLarge(file)) {
                        const message = STEM_PARAM_I18N.fileTooLarge;
                        error(message);
                        dispatchStemAlert(message, 'warning');

                        return {
                            abort: () => abort(),
                        };
                    }

                    let uploadStarted = false;

                    try {
                        uploadStarted = true;
                        lw.upload(
                            'audioFile',
                            file,
                            () => load(file.name),
                            (uploadError) => {
                                const message = normalizeUploadErrorMessage(uploadError);
                                error(message);
                                dispatchStemAlert(message, isUploadTooLargeError(uploadError) ? 'warning' : 'error');
                            },
                            (event) => {
                                progress(
                                    event.lengthComputable,
                                    event.loaded,
                                    event.total
                                );
                            }
                        );
                    } catch (uploadError) {
                        const message = normalizeUploadErrorMessage(uploadError);
                        error(message);
                        dispatchStemAlert(message, isUploadTooLargeError(uploadError) ? 'warning' : 'error');
                    }

                    return {
                        abort: () => {
                            if (uploadStarted && typeof lw.removeUpload === 'function') {
                                try {
                                    lw.removeUpload('audioFile', file.name, () => {});
                                } catch (_) {}
                            }

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

        S.bootTimer = setTimeout(async () => {
            S.bootTimer = null;
            formRestoreIfNeeded();
            bindParameterControls();
            watchAndPersistForm();
            await bootPond();
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
            waves: {},
            trackState: {},
            currentRender: null,
            isPlaying: false,
            isLoadingTracks: false,
            auditionTrack: null,
            eventsBound: false,
            commitHooked: false,
            masterTicker: null,
            listenersBound: false,
            bootTimer: null,
            masterTime: 0,
            duration: 0,
            leadTrack: null,
            loadingRenderId: null,
            readyRenderId: null,
            renderLoadPromise: null,
            renderPayloadPromise: null,
            loadToken: 0,
            lastDriftCheck: 0,
        };
    }

    const S = window.__STEM_RENDER_PAGE__;
    const renderI18nNode = document.getElementById('stem-render-i18n');
    let stemRenderI18nPayload = {};
    if (renderI18nNode) {
        try {
            stemRenderI18nPayload = JSON.parse(renderI18nNode.textContent || '{}');
        } catch (_) {
            stemRenderI18nPayload = {};
        }
    }
    const STEM_RENDER_I18N = {
        preparing: 'Preparing...',
        playAll: 'Play All',
        pauseAll: 'Pause All',
        play: 'Play',
        pause: 'Pause',
        loading: 'Loading',
        muted: 'Muted',
        mute: 'Mute',
        original: 'Original',
        vocals: 'Vocals',
        instrumental: 'Instrumental',
        drums: 'Drums',
        bass: 'Bass',
        other: 'Other',
        originalDescription: 'Original uploaded audio - muted by default',
        separatedTrack: 'Separated output track',
        seekAll: 'Click to seek all stems together',
        clickPlayToLoad: 'Click play to load the audio preview',
        loadingTrack: 'Loading track...',
        previewUnavailable: 'Preview unavailable',
        ...stemRenderI18nPayload,
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

    function getSelectedRenderIdFromDom() {
        return String(document.getElementById('stem-selected-render-id')?.value || '');
    }

    function buildStemPayloadUrl(jobId) {
        const template = document.getElementById('stem-render-payload-url-template')?.value || '';

        if (!template || !jobId) {
            return '';
        }

        return template.replace('__JOB_ID__', encodeURIComponent(String(jobId)));
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
        const hiddenIdEl = document.getElementById('stem-selected-render-id');

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

    function getLeadTrack() {
        if (S.leadTrack && S.waves[S.leadTrack]) {
            return S.leadTrack;
        }

        const firstTrack = Object.keys(S.waves).find((track) => track !== 'original')
            || Object.keys(S.waves)[0]
            || null;

        S.leadTrack = firstTrack;

        return firstTrack;
    }

    function getLeadWave() {
        const leadTrack = getLeadTrack();
        return leadTrack ? (S.waves[leadTrack] || null) : null;
    }

    function getTransportTime() {
        const leadWave = getLeadWave();
        const maxDuration = Number(S.duration || leadWave?.getDuration?.() || 0);

        if (!S.isPlaying || !leadWave) {
            return clamp(S.masterTime, 0, maxDuration || Math.max(S.masterTime, 0));
        }

        return clamp(Number(leadWave.getCurrentTime?.() || S.masterTime || 0), 0, maxDuration || Math.max(S.masterTime, 0));
    }

    function syncWaveTimes(seconds = S.masterTime, force = false) {
        const target = clamp(Number(seconds || 0), 0, S.duration || Math.max(Number(seconds || 0), 0));

        Object.values(S.waves).forEach((wave) => {
            if (!wave) return;

            const current = Number(wave.getCurrentTime?.() || 0);
            if (!force && Math.abs(current - target) < 0.08) {
                return;
            }

            try {
                wave.setTime(target);
            } catch (_) {
                try {
                    const duration = Number(wave.getDuration?.() || S.duration || 0);
                    if (duration > 0) {
                        wave.seekTo(clamp(target / duration, 0, 1));
                    }
                } catch (_) {}
            }
        });
    }

    function updateMasterControls() {
        const hasRender = !!S.currentRender;
        const busy = S.isLoadingTracks;
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

    function stopMasterTicker() {
        if (S.masterTicker) {
            cancelAnimationFrame(S.masterTicker);
            S.masterTicker = null;
        }
    }

    function resetPlaybackState({ clearAudition = false } = {}) {
        stopMasterTicker();
        S.isPlaying = false;
        S.lastDriftCheck = 0;

        if (clearAudition) {
            S.auditionTrack = null;
        }
    }

    function finishTransport({ targetTime = S.masterTime, clearAudition = false } = {}) {
        resetPlaybackState({ clearAudition });

        Object.values(S.waves).forEach((wave) => {
            try { wave.pause(); } catch (_) {}
        });

        S.masterTime = clamp(Number(targetTime || 0), 0, S.duration || Math.max(Number(targetTime || 0), 0));
        syncWaveTimes(S.masterTime, true);
        updateMasterTime(S.masterTime, S.duration);
        updateMasterControls();
        updateTrackButtonStates();
    }

    function startMasterTicker() {
        stopMasterTicker();

        const tick = (now) => {
            if (!S.isPlaying) {
                S.masterTicker = null;
                return;
            }

            S.masterTime = getTransportTime();
            updateMasterTime(S.masterTime, S.duration);

            if (!S.lastDriftCheck || (now - S.lastDriftCheck) > 220) {
                syncWaveTimes(S.masterTime, false);
                S.lastDriftCheck = now;
            }

            if (S.duration > 0 && S.masterTime >= (S.duration - 0.03)) {
                finishTransport({ targetTime: S.duration });
                return;
            }

            S.masterTicker = requestAnimationFrame(tick);
        };

        S.masterTicker = requestAnimationFrame(tick);
    }

    function destroyPlayers() {
        resetPlaybackState({ clearAudition: true });

        Object.values(S.waves).forEach((wave) => {
            try { wave.pause(); } catch (_) {}
            try { wave.destroy(); } catch (_) {}
        });

        S.waves = {};
        S.trackState = {};
        S.currentRender = null;
        S.isLoadingTracks = false;
        S.masterTime = 0;
        S.duration = 0;
        S.leadTrack = null;
        S.loadingRenderId = null;
        S.readyRenderId = null;
        S.renderLoadPromise = null;
        S.loadToken += 1;

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

    function wavePlaceholder(track, label = STEM_RENDER_I18N.clickPlayToLoad, loading = false) {
        const icon = loading
            ? '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>'
            : '<i class="mdi mdi-waveform"></i>';

        return `
            <div class="stem-wave__placeholder" data-track="${track}">
                ${icon}
                <span>${label}</span>
            </div>
        `;
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
                    <div id="wave-${track}" class="stem-wave tts-wave" data-track="${track}" title="${STEM_RENDER_I18N.seekAll}">
                        ${wavePlaceholder(track)}
                    </div>
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

            const wave = S.waves[track];
            if (!wave || typeof wave.setVolume !== 'function') return;

            try {
                wave.setVolume(volume);
            } catch (_) {}
        });
    }

    function seekTransport(seconds) {
        const target = clamp(Number(seconds || 0), 0, S.duration || Math.max(Number(seconds || 0), 0));
        S.masterTime = target;
        updateMasterTime(S.masterTime, S.duration);
        if (Object.keys(S.waves).length > 0) {
            syncWaveTimes(S.masterTime, true);
        }
    }

    function pauseTransport({ preserveTime = true } = {}) {
        finishTransport({
            targetTime: preserveTime ? getTransportTime() : 0,
        });
    }

    function stopTransport() {
        pauseTransport({ preserveTime: false });
    }

    async function playTransport(auditionTrack = S.auditionTrack) {
        if (!S.currentRender) return;

        const ready = await ensurePlaybackReady();
        if (!ready) return;

        S.auditionTrack = auditionTrack || null;
        S.masterTime = clamp(S.masterTime, 0, S.duration || Math.max(S.masterTime, 0));
        applyStemMix();
        syncWaveTimes(S.masterTime, true);

        const leadTrack = getLeadTrack();
        const playResults = await Promise.all(
            Object.entries(S.waves).map(async ([track, wave]) => {
                try {
                    await Promise.resolve(wave.play());
                    return { track, started: true };
                } catch (_) {
                    return { track, started: false };
                }
            })
        );

        const leadStarted = !leadTrack || playResults.some((result) => result.track === leadTrack && result.started);
        if (!getLeadWave() || !leadStarted) {
            Object.values(S.waves).forEach((wave) => {
                try { wave.pause(); } catch (_) {}
            });

            updateMasterControls();
            updateTrackButtonStates();
            return;
        }

        S.isPlaying = true;
        S.lastDriftCheck = 0;
        updateMasterTime(S.masterTime, S.duration);
        updateMasterControls();
        updateTrackButtonStates();
        startMasterTicker();
    }

    function createWave(track, url) {
        const container = document.getElementById(`wave-${track}`);
        if (!container || !url || !window.WaveSurfer) return Promise.resolve(null);

        container.innerHTML = wavePlaceholder(track, STEM_RENDER_I18N.loadingTrack, true);

        const color = STEM_COLORS[track] || '#4f46e5';

        return new Promise((resolve) => {
            let settled = false;
            const resolveOnce = (value) => {
                if (settled) return;
                settled = true;
                resolve(value);
            };

            try {
                container.innerHTML = '';

                const wave = WaveSurfer.create({
                    container,
                    url,
                    waveColor: color,
                    progressColor: color,
                    cursorColor: '#ffffff',
                    height: 60,
                    normalize: false,
                    autoScroll: false,
                    interact: false,
                    barWidth: 2,
                    barGap: 2,
                    barRadius: 2,
                    cursorWidth: 2,
                });

                wave.on('ready', () => {
                    const duration = Number(wave.getDuration?.() || 0);
                    if (duration > 0) {
                        S.duration = Math.max(S.duration, duration);
                    }

                    updateMasterTime(S.masterTime, S.duration);

                    try {
                        wave.setVolume(S.trackState[track]?.volume ?? 1);
                    } catch (_) {}

                    try {
                        wave.setTime(S.masterTime);
                    } catch (_) {}

                    resolveOnce(wave);
                });

                wave.on('error', () => {
                    container.innerHTML = wavePlaceholder(track, STEM_RENDER_I18N.previewUnavailable);
                    resolveOnce(null);
                });

                wave.on('finish', () => {
                    if (!S.isPlaying) return;
                    if (track !== getLeadTrack()) return;

                    finishTransport({
                        targetTime: S.duration || Number(wave.getDuration?.() || 0),
                    });
                });
            } catch (_) {
                container.innerHTML = wavePlaceholder(track, STEM_RENDER_I18N.previewUnavailable);
                resolveOnce(null);
            }
        });
    }

    function bindWaveSeekHandlers() {
        document.querySelectorAll('.stem-wave').forEach((waveEl) => {
            if (waveEl.dataset.seekBound === '1') return;
            waveEl.dataset.seekBound = '1';

            waveEl.addEventListener('click', async (event) => {
                const rect = waveEl.getBoundingClientRect();
                if (rect.width <= 0) return;

                const ratio = clamp((event.clientX - rect.left) / rect.width, 0, 1);
                if (String(S.readyRenderId || '') !== String(S.currentRender?.id || '')) {
                    const ready = await ensurePlaybackReady();
                    if (!ready) return;
                }

                const duration = Number(S.duration || getLeadWave()?.getDuration?.() || 0);

                if (!(duration > 0)) return;

                seekTransport(duration * ratio);
            });
        });
    }

    function updateTrackButtonStates() {
        document.querySelectorAll('.track-play').forEach((button) => {
            const track = button.dataset.track;
            const active = S.auditionTrack === track;
            const hasTrack = !!S.currentRender && !!S.trackState[track];

            button.classList.toggle('active', active);
            button.classList.toggle('btn-success', active);
            button.classList.toggle('btn-outline-success', !active);
            button.disabled = !hasTrack || S.isLoadingTracks;
            button.setAttribute('aria-pressed', active ? 'true' : 'false');

            if (S.isLoadingTracks) {
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
            button.disabled = !S.trackState[track];
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        document.querySelectorAll('.track-mute').forEach((button) => {
            const track = button.dataset.track;
            const muted = !!S.trackState[track]?.mute;

            button.classList.toggle('active', muted);
            button.classList.toggle('btn-warning', muted);
            button.classList.toggle('btn-outline-warning', !muted);
            button.disabled = !S.trackState[track];
            button.setAttribute('aria-pressed', muted ? 'true' : 'false');
            button.innerHTML = `<i class="mdi mdi-volume-off"></i> ${muted ? STEM_RENDER_I18N.muted : STEM_RENDER_I18N.mute}`;
        });
    }

    function bindTrackButtons() {
        document.querySelectorAll('.track-play').forEach((button) => {
            button.onclick = async () => {
                const track = button.dataset.track;
                if (!S.trackState[track] || S.isLoadingTracks) return;

                if (S.auditionTrack === track && S.isPlaying) {
                    pauseTransport({ preserveTime: true });
                    return;
                }

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

    async function ensurePlaybackReady() {
        const render = S.currentRender;
        if (!render || !render.id || !Array.isArray(render.tracks)) {
            return false;
        }

        const renderId = String(render.id);
        if (S.readyRenderId === renderId && Object.keys(S.waves).length > 0) {
            return true;
        }

        if (S.renderLoadPromise && S.loadingRenderId === renderId) {
            return S.renderLoadPromise;
        }

        try {
            await ensureStemRenderAssetsReady();
        } catch (error) {
            console.warn('[STEM] Failed to load WaveSurfer assets', error);
            return false;
        }

        const token = S.loadToken + 1;
        S.loadToken = token;
        S.isLoadingTracks = true;
        S.loadingRenderId = renderId;
        S.readyRenderId = null;
        S.duration = 0;

        Object.values(S.waves).forEach((wave) => {
            try { wave.pause(); } catch (_) {}
            try { wave.destroy(); } catch (_) {}
        });
        S.waves = {};

        (render.tracks || []).forEach((track) => {
            const container = document.getElementById(`wave-${track}`);
            if (container) {
                container.innerHTML = wavePlaceholder(track, STEM_RENDER_I18N.loadingTrack, true);
            }
        });

        updateMasterControls();
        updateTrackButtonStates();
        updateMasterTime(S.masterTime, S.duration);

        const loadPromise = (async () => {
            try {
                const results = await Promise.all(
                    (render.tracks || []).map(async (track) => {
                        const url = render.stems?.[track] || null;

                        if (!url) {
                            const container = document.getElementById(`wave-${track}`);
                            if (container) {
                                container.innerHTML = wavePlaceholder(track, STEM_RENDER_I18N.previewUnavailable);
                            }

                            return { track, wave: null };
                        }

                        return {
                            track,
                            wave: await createWave(track, url),
                        };
                    })
                );

                if (S.loadToken !== token || String(S.currentRender?.id || '') !== renderId) {
                    results.forEach((result) => {
                        if (result?.wave) {
                            try { result.wave.destroy(); } catch (_) {}
                        }
                    });

                    return false;
                }

                const nextWaves = {};
                results.forEach((result) => {
                    if (result?.wave) {
                        nextWaves[result.track] = result.wave;
                    }
                });

                S.waves = nextWaves;
                S.readyRenderId = Object.keys(nextWaves).length > 0 ? renderId : null;
                S.duration = Object.values(nextWaves).reduce((carry, wave) => {
                    return Math.max(carry, Number(wave.getDuration?.() || 0));
                }, 0);

                applyStemMix();
                syncWaveTimes(S.masterTime, true);
                updateMasterTime(S.masterTime, S.duration);

                return Object.keys(nextWaves).length > 0;
            } catch (error) {
                console.warn('[STEM] Failed to prepare track previews', error);
                return false;
            } finally {
                if (S.loadToken === token && String(S.currentRender?.id || '') === renderId) {
                    S.isLoadingTracks = false;
                    S.loadingRenderId = null;
                    S.renderLoadPromise = null;
                    updateMasterControls();
                    updateTrackButtonStates();
                }
            }
        })();

        S.renderLoadPromise = loadPromise;

        return loadPromise;
    }

    async function ensureStemRenderAssetsReady() {
        if (typeof window.__ensureStemRenderAssets === 'function') {
            await window.__ensureStemRenderAssets();
            return;
        }

        if (window.WaveSurfer) {
            return;
        }

        throw new Error('WaveSurfer loader is unavailable');
    }

    async function loadStemRenderById(jobId, options = {}) {
        const { allowCache = true, persist = true, force = false } = options;
        const normalizedId = String(jobId || '');

        if (!normalizedId) {
            clearStemRenderUI();
            return null;
        }

        const currentId = String(S.currentRender?.id || '');
        const cached = allowCache ? loadRenderFromCache(normalizedId) : null;

        if (cached && (force || currentId !== normalizedId)) {
            await loadStemRender(cached, { persist: false });
        }

        if (!force && currentId === normalizedId && S.readyRenderId === normalizedId) {
            return S.currentRender;
        }

        if (S.loadingRenderId === normalizedId && S.renderPayloadPromise) {
            return S.renderPayloadPromise;
        }

        const url = buildStemPayloadUrl(normalizedId);
        if (!url) {
            return cached;
        }

        S.loadingRenderId = normalizedId;

        const request = (async () => {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error(`Failed to load STEM render (${response.status})`);
            }

            let render = null;
            try {
                render = await response.json();
            } catch (_) {
                throw new Error(`Failed to parse STEM render payload (${response.status})`);
            }
            await loadStemRender(render, { persist });

            return render;
        })();

        S.renderPayloadPromise = request;

        try {
            return await request;
        } finally {
            if (S.loadingRenderId === normalizedId) {
                S.loadingRenderId = null;
            }

            if (S.renderPayloadPromise === request) {
                S.renderPayloadPromise = null;
            }
        }
    }

    async function loadStemRender(render, options = {}) {
        if (!render || !render.id || !Array.isArray(render.tracks)) return;

        const { persist = true } = options;
        const renderId = String(render.id);
        const currentId = String(S.currentRender?.id || '');
        const sameRender = currentId === renderId;
        const tracksMounted = document.querySelectorAll('#stem-tracks .stem-track-row').length === (render.tracks || []).length;

        if (!sameRender) {
            destroyPlayers();
        } else if (!tracksMounted) {
            finishTransport({ targetTime: getTransportTime() });

            Object.values(S.waves).forEach((wave) => {
                try { wave.pause(); } catch (_) {}
                try { wave.destroy(); } catch (_) {}
            });

            S.loadToken += 1;
            S.waves = {};
            S.isLoadingTracks = false;
            S.loadingRenderId = null;
            S.readyRenderId = null;
            S.renderLoadPromise = null;
        }

        S.currentRender = sameRender && S.currentRender
            ? { ...S.currentRender, ...render }
            : render;
        S.leadTrack = render.tracks.find((track) => track !== 'original') || render.tracks[0] || null;

        if (!sameRender || Object.keys(S.trackState).length === 0) {
            S.trackState = defaultStateForTracks(render.tracks);
            S.auditionTrack = null;
            S.masterTime = 0;
            S.duration = 0;
        } else {
            const defaults = defaultStateForTracks(render.tracks);
            const nextState = {};

            (render.tracks || []).forEach((track) => {
                nextState[track] = {
                    ...(defaults[track] || {}),
                    ...(S.trackState[track] || {}),
                };
            });

            S.trackState = nextState;
        }

        if (persist) {
            saveRenderCache(S.currentRender);
        }

        if (!tracksMounted || !sameRender) {
            if (!ensureTrackRows(S.currentRender)) return;
        }

        setPlayerVisibility(true);
        updateLatestHeader(S.currentRender);
        applyStemMix();
        bindTrackButtons();
        bindWaveSeekHandlers();
        updateMasterControls();
        updateTrackButtonStates();
        updateMasterTime(S.masterTime, S.duration);

        if (S.readyRenderId === renderId && Object.keys(S.waves).length > 0) {
            syncWaveTimes(S.masterTime, true);
        }
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
        const serverId = getSelectedRenderIdFromDom();
        const currentId = String(S.currentRender?.id || '');

        if (serverId) {
            if (currentId !== serverId || S.readyRenderId !== serverId) {
                loadStemRenderById(serverId, { allowCache, persist: true }).catch((error) => {
                    console.warn('[STEM] Failed to sync render payload', error);
                });
                return;
            }

            updateLatestHeader(S.currentRender);
            setPlayerVisibility(true);
            bindTrackButtons();
            bindWaveSeekHandlers();
            updateMasterControls();
            updateTrackButtonStates();

            const rowsMounted = document.querySelectorAll('#stem-tracks .stem-track-row').length === (S.currentRender?.tracks || []).length;
            if (!rowsMounted) {
                loadStemRenderById(serverId, { allowCache, persist: true, force: true }).catch((error) => {
                    console.warn('[STEM] Failed to rebuild render rows', error);
                });
                return;
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

        Livewire.on('stem-render-selected', (event) => {
            const jobId = event?.jobId || null;
            if (jobId) {
                loadStemRenderById(jobId, { allowCache: true, persist: true }).catch((error) => {
                    console.warn('[STEM] Failed to load selected render', error);
                });
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

            const jobId = event?.jobId || null;
            if (jobId) {
                loadStemRenderById(jobId, { allowCache: false, persist: true, force: true }).catch((error) => {
                    console.warn('[STEM] Failed to load completed render', error);
                });
            }

            requestAnimationFrame(() => {
                highlightLatestRender();
            });
        });
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
