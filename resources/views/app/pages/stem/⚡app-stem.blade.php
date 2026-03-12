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
                'original' => 'ORIGINAL',
                'vocals' => 'VOCALS',
                'instrumental' => 'INSTRUMENTAL',
                'drums' => 'DRUMS',
                'bass' => 'BASS',
                'other' => 'OTHER',
                default => strtoupper($track),
            };
        };

        $latestTitle = $loadedRender['input_name'] ?? null;
        $latestTitle = $latestTitle ?: ($loadedRender['id'] ?? '—');

        $status = $currentStatus ?? 'queued';
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
                                    Job ID: <span class="font-weight-bold">{{ $currentJobId }}</span>
                                </small>
                            </div>

                            <div>
                                @if(!$jobFinished)
                                    <small class="tts-status-muted">
                                        <span class="spinner-border spinner-border-sm mr-1" role="status"></span>
                                        Working...
                                    </small>
                                @else
                                    <small class="tts-status-muted">Finished</small>
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
                                    <strong class="d-block">Upload Audio</strong>
                                    <small class="text-muted">Separate stems with Demucs / MDX</small>
                                </div>
                                <span class="badge badge-primary">Beta</span>
                            </div>

                            <div class="d-flex flex-wrap gap-3 mb-3 stem-top-mini-stats">
                                <div class="stem-mini-stat">
                                    <div class="text-muted small">Wallet</div>
                                    <div class="fw-semibold">{{ number_format($walletBalance) }}</div>
                                </div>
                                <div class="stem-mini-stat">
                                    <div class="text-muted small">Cost</div>
                                    <div class="fw-semibold">{{ number_format($creditsCost) }}</div>
                                </div>
                                @if($audioDurationMin)
                                    <div class="stem-mini-stat">
                                        <div class="text-muted small">Minutes</div>
                                        <div class="fw-semibold">{{ number_format((float) $audioDurationMin, 2) }}</div>
                                    </div>
                                @endif
                            </div>

                            <div class="mb-3">
                                <label class="mb-1 font-weight-medium">Audio File</label>
                                <div wire:ignore>
                                    <input type="file" id="stem-audio-pond">
                                </div>

                                <div wire:loading wire:target="audioFile" class="small text-primary mt-2">
                                    Uploading audio...
                                </div>

                                <small class="text-muted d-block mt-2">
                                    WAV recommended • Max 100MB
                                </small>
                            </div>

                            @if($audioFileName)
                                <div class="stem-upload-meta mb-3">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                        <div>
                                            <div class="fw-semibold">{{ $audioFileName }}</div>
                                            <div class="small text-muted">
                                                {{ $audioDurationSec ? number_format($audioDurationSec, 2) . ' sec' : 'Unknown duration' }}
                                                @if($audioFileBytes)
                                                    • {{ number_format($audioFileBytes / 1024 / 1024, 2) }} MB
                                                @endif
                                            </div>
                                        </div>

                                        <button class="btn btn-outline-danger btn-sm" wire:click="removeAudioFile" type="button">
                                            Remove
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
                                            {{ $this->canSeparate ? 'Separate' : ($this->separateBlockedReason ?? 'Separate') }}
                                        </span>

                                        <span wire:loading wire:target="audioFile">
                                            <span class="spinner-border spinner-border-sm mr-1"></span>
                                            Uploading audio...
                                        </span>

                                        <span wire:loading wire:target="submit">
                                            <span class="spinner-border spinner-border-sm mr-1"></span>
                                            Sending...
                                        </span>
                                    </button>

                                    <button class="btn btn-outline-secondary" wire:click="resetForm" type="button">
                                        Clear
                                    </button>
                                </div>

                                @if($currentJobId && !$jobFinished)
                                    <button type="button" class="btn btn-outline-danger" wire:click="openEliminateModal">
                                        Eliminate Current Job
                                    </button>
                                @endif
                            </div>

                            @if($walletBalance < $creditsCost && $creditsCost > 0)
                                <small class="text-danger d-block mt-2">
                                    Not enough credits for this separation.
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
                                    <strong class="d-block">Latest Output</strong>
                                    <small class="text-muted">Title: <b>{{ $latestTitle }}</b></small>
                                </div>

                                <div class="d-flex align-items-center gap-2">
                                    @if($loadedRender && isset($loadedRender['downloads']['all']))
                                        <small class="text-muted mt-2 mt-md-0 mr-2">
                                            Render: <b>#{{ $loadedRender['id'] }}</b>
                                        </small>
                                        <a
                                            id="stem-download-all"
                                            href="{{ $loadedRender['downloads']['all'] ?? '#' }}"
                                            class="btn btn-sm btn-primary {{ isset($loadedRender['downloads']['all']) ? '' : 'disabled' }}"
                                            title="Download All as ZIP"
                                        >
                                            <i class="mdi mdi-download"></i> Download ZIP
                                        </a>
                                    @else
                                        <a
                                            id="stem-download-all"
                                            href="#"
                                            class="btn btn-sm btn-primary disabled"
                                            title="Download All as ZIP"
                                        >
                                            <i class="mdi mdi-download"></i> Download ZIP
                                        </a>
                                    @endif
                                </div>
                            </div>

                            <input type="hidden" id="stem-latest-ready" value="{{ $loadedRender ? 1 : 0 }}">
                            <input type="hidden" id="stem-latest-render-id" value="{{ $loadedRender['id'] ?? '' }}">

                            <div id="stem-empty-state" class="{{ $loadedRender ? 'd-none' : '' }}">
                                <div class="text-muted small text-center py-4">No output selected yet.</div>
                            </div>

                            <div id="stem-tracks-wrapper" wire:ignore class="{{ $loadedRender ? '' : 'd-none' }}">
                                <hr class="mt-3">

                                <div class="stem-player-container">
                                    <div class="master-controls mb-3">
                                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                            <div class="d-flex align-items-center gap-2">
                                                <button id="stem-master-play" type="button" class="btn btn-lg btn-success btn-master-play" {{ $loadedRender ? '' : 'disabled' }}>
                                                    <i class="mdi mdi-play"></i> Play All
                                                </button>
                                                <button id="stem-master-stop" type="button" class="btn btn-lg btn-outline-secondary btn-master-stop" {{ $loadedRender ? '' : 'disabled' }}>
                                                    <i class="mdi mdi-stop"></i> Stop
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
                                                            {{ $track === 'original' ? 'Original uploaded audio — muted by default' : 'Separated output track' }}
                                                        </div>
                                                    </div>

                                                    <div class="stem-track-controls d-flex gap-2 flex-wrap">
                                                        <button type="button" class="btn btn-sm btn-stem-solo track-solo" data-track="{{ $track }}">
                                                            <i class="mdi mdi-headphones"></i> S
                                                        </button>

                                                        <button type="button" class="btn btn-sm btn-stem-mute track-mute {{ $isMutedByDefault ? 'active btn-warning' : 'btn-outline-warning' }}" data-track="{{ $track }}">
                                                            <i class="mdi mdi-volume-off"></i> {{ $isMutedByDefault ? 'Muted' : 'Mute' }}
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
                                    Recent renders and active job state are cached in browser storage for instant reload.
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
                        <div class="card-body p-3 p-md-4">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <strong>Parameters</strong>
                                <button wire:click="resetForm" class="btn btn-sm btn-outline-secondary" type="button">
                                    <i class="mdi mdi-refresh"></i> Reset
                                </button>
                            </div>

                            <hr>

                            <div class="mb-3">
                                <label class="mb-1"><b>Stems Mode</b></label>
                                <div class="btn-group btn-group-toggle d-flex" data-toggle="buttons">
                                    <label class="btn btn-outline-secondary {{ $stems == 2 ? 'active' : '' }}">
                                        <input type="radio" wire:model.live="stems" value="2"> 2 Stems
                                    </label>
                                    <label class="btn btn-outline-secondary {{ $stems == 4 ? 'active' : '' }}">
                                        <input type="radio" wire:model.live="stems" value="4"> 4 Stems
                                    </label>
                                </div>
                                <small class="text-muted d-block mt-2">
                                    2 = vocals + instrumental • 4 = vocals + drums + bass + other
                                </small>
                            </div>

                            <hr>

                            <div class="mb-3">
                                <label class="mb-1"><b>Choose Stem Model</b></label>
                                <select wire:model.live="model" class="form-control rounded-pill">
                                    <option value="htdemucs_ft">htdemucs_ft</option>
                                </select>
                                <small class="form-text text-muted">htdemucs_ft is usually best quality</small>
                            </div>

                            <hr>

                            <div class="mb-3">
                                <label class="mb-1"><b>Output Format</b></label>
                                <div class="btn-group btn-group-toggle d-flex" data-toggle="buttons">
                                    <label class="btn btn-outline-secondary {{ $stemCodec === 'mp3' ? 'active' : '' }}">
                                        <input type="radio" wire:model.live="stemCodec" value="mp3"> MP3
                                    </label>
                                </div>
                            </div>

                            <hr>

                            <div class="mb-0">
                                <label class="mb-1"><b>Bitrate</b></label>
                                <select wire:model.live="stemBitrate" class="form-control rounded-pill">
                                    <option value="192k">192k</option>
                                </select>
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
                        </div>
                    </div>
                </div>
            </div>


            <div class="turbo-border mb-3">
                <div class="turbo-inner">
                    <div class="card mb-0">
                        <div class="card-body p-3 p-md-4">
                            <div class="d-flex align-items-center justify-content-between mb-2 gap-2 flex-wrap">
                                <strong>Recent Renders</strong>
                                <span class="badge badge-secondary">{{ $this->recentRenders->total() }}</span>
                            </div>

                            <div class="mb-3">
                                <input
                                    type="text"
                                    class="form-control form-control-sm"
                                    placeholder="Search..."
                                    wire:model.live.debounce.300ms="search"
                                >
                            </div>

                            @if($this->recentRenders->count() === 0)
                                <div class="text-muted small text-center py-4">No renders yet.</div>
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
                                                                <b class="d-block text-truncate" style="max-width: 200px;">{{ $render['input_name'] ?: 'Untitled audio' }}</b>
                                                                <small class="text-muted d-block">Mode: {{ $render['mode'] }}-Stem</small>
                                                                <small class="text-muted d-block">{{ $render['created_at'] }}</small>
                                                            </div>
                                                        </div>
                                                    </button>
                                                </div>

                                                <div class="d-flex align-items-center gap-1 ml-2">
                                                    <a href="{{ $render['downloads']['all'] }}" class="btn btn-sm btn-outline-primary" title="ZIP">
                                                        <i class="mdi mdi-download"></i>
                                                    </a>

                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-outline-danger"
                                                        wire:click.stop="deleteRender('{{ $render['id'] }}')"
                                                        onclick="return confirm('Delete this render? This will remove files and deduct storage.');"
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

    .stem-player-container {
        background: rgba(0,0,0,.05);
        border-radius: 12px;
        padding: 20px;
    }

    .master-controls {
        background: rgba(255,255,255,.05);
        padding: 15px;
        border-radius: 8px;
        border: 1px solid rgba(255,255,255,.1);
    }

    .master-time {
        font-size: 1.1rem;
        font-weight: 600;
    }

    .stem-track {
        background: rgba(255,255,255,.03);
        border: 1px solid color-mix(in srgb, var(--stem-color) 40%, rgba(255,255,255,.10));
        border-left: 4px solid var(--stem-color);
        border-radius: 8px;
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

    .btn-stem-solo,
    .btn-stem-mute {
        min-width: 44px;
        font-weight: 600;
        transition: all 0.2s ease;
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
            masterTicker: null,
        };
    }

    const S = window.__STEM_RENDER_PAGE__;
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

    function formatTime(sec) {
        sec = Math.max(0, Number(sec || 0));
        const m = String(Math.floor(sec / 60)).padStart(2, '0');
        const s = String(Math.floor(sec % 60)).padStart(2, '0');
        return `${m}:${s}`;
    }

    function updateMasterTime() {
        const first = Object.values(S.players)[0];
        const currentEl = document.getElementById('master-current');
        const durationEl = document.getElementById('master-duration');

        if (!currentEl || !durationEl || !first) return;

        try {
            currentEl.textContent = formatTime(first.getCurrentTime?.() || 0);
            durationEl.textContent = formatTime(first.getDuration?.() || 0);
        } catch (_) {}
    }

    function startMasterTicker() {
        stopMasterTicker();
        S.masterTicker = setInterval(updateMasterTime, 250);
    }

    function stopMasterTicker() {
        if (S.masterTicker) {
            clearInterval(S.masterTicker);
            S.masterTicker = null;
        }
    }

    function destroyPlayers() {
        stopMasterTicker();

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
            case 'original': return 'ORIGINAL';
            case 'vocals': return 'VOCALS';
            case 'instrumental': return 'INSTRUMENTAL';
            case 'drums': return 'DRUMS';
            case 'bass': return 'BASS';
            case 'other': return 'OTHER';
            default: return String(track || '').toUpperCase();
        }
    }

    function trackDescription(track) {
        return track === 'original'
            ? 'Original uploaded audio — muted by default'
            : 'Separated output track';
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
                            <button type="button" class="btn btn-sm btn-stem-solo track-solo" data-track="${track}">
                                <i class="mdi mdi-headphones"></i> S
                            </button>
                            <button type="button" class="btn btn-sm btn-stem-mute track-mute ${muted ? 'active btn-warning' : 'btn-outline-warning'}" data-track="${track}">
                                <i class="mdi mdi-volume-off"></i> ${muted ? 'Muted' : 'Mute'}
                            </button>
                            <a href="${downloadUrl}" class="btn btn-sm btn-outline-primary${downloadUrl === '#' ? ' disabled' : ''}">
                                <i class="mdi mdi-download"></i>
                            </a>
                        </div>
                    </div>
                    <div id="wave-${track}" class="stem-wave tts-wave"></div>
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

        const color = STEM_COLORS[track] || '#4f46e5';

        const player = WaveSurfer.create({
            container,
            waveColor: color,
            progressColor: color,
            cursorColor: color,
            height: 60,
            normalize: true,
            autoScroll: false,
            barWidth: 2,
            barGap: 2,
            barRadius: 2,
        });

        player.load(url);

        player.on('ready', updateMasterTime);
        player.on('audioprocess', updateMasterTime);

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
            button.classList.toggle('btn-outline-primary', !active);
        });

        document.querySelectorAll('.track-mute').forEach((button) => {
            const track = button.dataset.track;
            const muted = !!S.trackState[track]?.mute;

            button.classList.toggle('active', muted);
            button.classList.toggle('btn-warning', muted);
            button.classList.toggle('btn-outline-warning', !muted);
            button.innerHTML = `<i class="mdi mdi-volume-off"></i> ${muted ? 'Muted' : 'Mute'}`;
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
                startMasterTicker();
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
                updateMasterTime();
                stopMasterTicker();
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
                updateMasterTime();
            }, 250);
        });
    }

    function clearStemRenderUI() {
        const tracksEl = document.getElementById('stem-tracks');
        if (tracksEl) {
            tracksEl.innerHTML = '';
        }

        destroyPlayers();
        setPlayerVisibility(false);
        updateMasterTime();
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
            updateMasterTime();
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