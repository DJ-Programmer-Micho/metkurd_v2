<?php

use App\Models\MlJob;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Services\Billing\CreditService;
use App\Services\Security\JobExecutionLockService;
use App\Services\Youtube\ProcessYoutubeDownloadJob;
use App\Services\Youtube\YoutubeJobSyncService;
use App\Services\Youtube\YoutubePreviewService;
use Illuminate\Contracts\Session\Session as SessionContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('app::layouts.app')]
#[Title('YOUTUBE DOWNLOADER | METKURD')]
class extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    protected string $audioToolCode = 'youtube_audio';

    protected string $videoToolCode = 'youtube_video';

    #[Url(as: 'page', except: 1)]
    public int $page = 1;

    // =========================================================
    // UI State
    // =========================================================
    public ?string $currentJobId = null;

    public ?string $currentStatus = null;

    public bool $jobFinished = false;

    public bool $showJobStatus = false;

    public int $currentProgress = 0;

    public ?string $currentPhase = null;

    public ?string $currentStatusMessage = null;

    public ?float $currentSpeedBps = null;

    public ?int $currentEtaSec = null;

    public ?int $currentDownloadedBytes = null;

    public ?int $currentTotalBytes = null;

    public ?int $currentElapsedSec = null;

    public ?int $currentQueuedForSec = null;

    public ?string $currentProgressUpdatedAt = null;

    public ?string $currentProgressTitle = null;

    public ?string $currentProgressFileName = null;

    public ?int $currentPlaylistIndex = null;

    public ?int $currentPlaylistCount = null;

    public array $currentLogLines = [];

    public ?string $dismissedJobStatusFor = null;

    public bool $showEliminateModal = false;

    // =========================================================
    // Inputs
    // =========================================================
    public string $url = '';

    public string $downloadMode = 'audio'; // audio | video

    public string $audioFormat = 'mp3';    // mp3 | wav

    public string $videoQuality = 'p720';  // p480 | p720 | p1080 | p4k

    public bool $includeThumbnail = false;

    // =========================================================
    // Preview State
    // =========================================================
    public bool $isPreviewLoaded = false;

    public ?string $previewType = null;       // video | playlist

    public ?string $previewTitle = null;

    public ?string $previewUploader = null;

    public ?string $previewThumbnail = null;

    public ?int $previewDurationSec = null;

    public ?int $previewBillableMinutes = null;

    public ?int $previewEntriesCount = null;

    public ?string $previewWebpageUrl = null;

    public array $previewEntries = [];

    // =========================================================
    // Billing UI
    // =========================================================
    public int $walletBalance = 0;

    public int $creditsCost = 0;

    // =========================================================
    // Internal
    // =========================================================
    public int $downloadsRefreshKey = 0;

    // =========================================================
    // Lifecycle
    // =========================================================
    public function mount(): void
    {
        $this->normalizeDownloadOptions();
        $this->syncWallet();
        $this->syncCostPreview();

        $this->dismissedJobStatusFor = session('youtube.dismissed_job_status_for');

        $this->hydrateCurrentJobFromDb();
    }

    #[On('header:refresh')]
    #[On('customerPlanUpdated')]
    #[On('customerStorageUpdated')]
    #[On('youtube-downloads-refresh')]
    public function refreshUi(): void
    {
        $this->syncWallet();
        $this->syncCostPreview();
        $this->hydrateCurrentJobFromDb();
        $this->downloadsRefreshKey++;
    }

    // =========================================================
    // Watchers
    // =========================================================
    public function updatedDownloadMode(): void
    {
        $this->normalizeDownloadOptions();
        $this->resetValidation(['audioFormat', 'videoQuality']);
        $this->syncCostPreview();
    }

    public function updatedAudioFormat(): void
    {
        $this->normalizeDownloadOptions();
        $this->syncCostPreview();
    }

    public function updatedVideoQuality(): void
    {
        $this->normalizeDownloadOptions();
        $this->syncCostPreview();
    }

    public function updatedUrl(): void
    {
        $this->resetPreview();
    }

    // =========================================================
    // Computed
    // =========================================================
    #[Computed]
    public function canStartDownload(): bool
    {
        return $this->downloadBlockedReason === null;
    }

    #[Computed]
    public function downloadBlockedReason(): ?string
    {
        if ($this->isProcessing() || $this->currentActiveJobsCount() >= 1) {
            return 'A YouTube download job is already in progress.';
        }

        if (! $this->isPreviewLoaded) {
            return 'Please preview the URL first.';
        }

        if (! $this->validYouTubeUrl($this->url)) {
            return 'Please enter a valid YouTube URL.';
        }

        if ($this->creditsCost <= 0) {
            return 'Pricing could not be calculated.';
        }

        if ($this->walletBalance < $this->creditsCost) {
            return 'Not enough credits.';
        }

        return null;
    }

    #[Computed]
    public function downloads()
    {
        $this->downloadsRefreshKey;

        $customerId = auth('app')->id();
        if (! $customerId) {
            return MlJob::query()->whereRaw('1=0')->paginate(6);
        }

        $toolIds = Tool::query()
            ->whereIn('code', [$this->audioToolCode, $this->videoToolCode])
            ->pluck('id')
            ->all();

        $paginator = MlJob::query()
            ->where('customer_id', $customerId)
            ->when(! empty($toolIds), fn ($q) => $q->whereIn('tool_id', $toolIds))
            ->whereIn('status', ['done', 'delete_failed', 'deleted'])
            ->orderByDesc('finished_at')
            ->paginate(6);

        $paginator->setCollection(
            $paginator->getCollection()->values()->map(function ($job, $index) {
                $outputName = (string) (
                    data_get($job->output, 'download_name')
                    ?: data_get($job->output, 'file_name')
                    ?: data_get($job->input, 'title')
                    ?: 'Download'
                );

                $mode = (string) data_get($job->input, 'mode', 'audio');

                return [
                    'id' => (string) $job->id,
                    'status' => (string) $job->status,
                    'title' => (string) data_get($job->input, 'title', 'YouTube Download'),
                    'uploader' => (string) data_get($job->input, 'uploader', ''),
                    'mode' => $mode,
                    'quality' => (string) data_get($job->input, 'quality', ''),
                    'format' => (string) data_get($job->input, 'format', ''),
                    'thumbnail' => (string) data_get($job->input, 'thumbnail', ''),
                    'created_at' => optional($job->finished_at ?? $job->created_at)->format('Y-m-d H:i'),
                    'entries_count' => (int) data_get($job->input, 'entries_count', 0),
                    'duration_sec' => (int) data_get($job->input, 'duration_sec', 0),
                    'credits_charged' => (int) ($job->credits_charged ?? 0),
                    'storage_out_bytes' => (int) ($job->storage_out_bytes ?? 0),
                    'download_name' => $outputName,
                    'source_url' => (string) data_get($job->input, 'url', ''),
                    'can_delete' => (string) $job->status !== 'deleted',
                    'is_latest' => $index === 0,
                ];
            })
        );

        return $paginator;
    }

    // =========================================================
    // Rules
    // =========================================================
    protected function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2000'],
            'downloadMode' => ['required', 'in:audio,video'],
            'audioFormat' => [
                'nullable',
                Rule::requiredIf(fn () => $this->downloadMode === 'audio'),
                Rule::in($this->allowedAudioFormats()),
            ],
            'videoQuality' => [
                'nullable',
                Rule::requiredIf(fn () => $this->downloadMode === 'video'),
                Rule::in($this->allowedVideoQualities()),
            ],
            'includeThumbnail' => ['boolean'],
        ];
    }

    // =========================================================
    // Helpers
    // =========================================================
    protected function validYouTubeUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        return (bool) preg_match(
            '/^(https?\:\/\/)?(www\.)?(youtube\.com|youtu\.be)\//i',
            $url
        );
    }

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
        $this->normalizeDownloadOptions();

        $customer = auth('app')->user();
        $entries = $this->previewType === 'playlist'
            ? max(1, (int) ($this->previewEntriesCount ?? 0))
            : 1;

        if (! $customer) {
            $this->creditsCost = 0;

            return;
        }

        // Fallback estimation until exact preview-based billing is finalized.
        if ($this->downloadMode === 'audio') {
            $code = "youtube_audio.{$this->audioFormat}";
            $singleFallback = $this->audioFormat === 'wav' ? 1800 : 1000;
        } else {
            $code = "youtube_video.{$this->videoQuality}";
            $singleFallback = match ($this->videoQuality) {
                'p480' => 1500,
                'p720' => 2500,
                'p1080' => 4000,
                'p4k' => 7000,
                default => 2500,
            };
        }

        $fallback = $singleFallback * $entries;

        if (method_exists($customer, 'priceCreditsFor')) {
            try {
                $calculated = (int) $customer->priceCreditsFor($code, [
                    'metric_code' => 'minute',
                    'minutes' => max(1, $this->estimatedBillableMinutes()),
                    'entries' => $entries,
                ]);

                if ($calculated > 0) {
                    $this->creditsCost = $calculated;

                    return;
                }
            } catch (\Throwable $e) {
                // fallback below
            }
        }

        $this->creditsCost = $fallback;
    }

    protected function allowedAudioFormats(): array
    {
        return ['mp3', 'wav'];
    }

    protected function allowedVideoQualities(): array
    {
        return ['p480', 'p720', 'p1080', 'p4k'];
    }

    protected function normalizeDownloadOptions(): void
    {
        if (! in_array($this->downloadMode, ['audio', 'video'], true)) {
            $this->downloadMode = 'audio';
        }

        if (! in_array($this->videoQuality, $this->allowedVideoQualities(), true)) {
            $this->videoQuality = 'p720';
        }

        if (! in_array($this->audioFormat, $this->allowedAudioFormats(), true)) {
            $this->audioFormat = 'mp3';
        }
    }

    protected function estimatedBillableMinutes(): int
    {
        if ($this->previewType === 'playlist') {
            if (($this->previewBillableMinutes ?? 0) > 0) {
                return (int) $this->previewBillableMinutes;
            }

            return max(1, (int) ($this->previewEntriesCount ?? 0));
        }

        $seconds = (int) ($this->previewDurationSec ?? 0);
        if ($seconds <= 0) {
            return 1;
        }

        return max(1, (int) ceil($seconds / 60));
    }

    protected function currentActiveJobsCount(): int
    {
        $customerId = auth('app')->id();
        if (! $customerId) {
            return 0;
        }

        $toolIds = Tool::query()
            ->whereIn('code', [$this->audioToolCode, $this->videoToolCode])
            ->pluck('id')
            ->all();

        return MlJob::query()
            ->where('customer_id', $customerId)
            ->when(! empty($toolIds), fn ($q) => $q->whereIn('tool_id', $toolIds))
            ->whereIn('status', ['queued', 'running', 'saving'])
            ->whereNotNull('lock_expires_at')
            ->where('lock_expires_at', '>', now())
            ->count();
    }

    protected function isProcessing(): bool
    {
        return (bool) $this->currentJobId
            && ! $this->jobFinished
            && in_array($this->currentStatus, ['queued', 'running', 'saving'], true);
    }

    protected function findToolAndAction(): array
    {
        $this->normalizeDownloadOptions();

        if ($this->downloadMode === 'audio') {
            $tool = Tool::query()->where('code', $this->audioToolCode)->first();
            $action = ToolAction::query()
                ->where('full_code', "youtube_audio.{$this->audioFormat}")
                ->first();
        } else {
            $tool = Tool::query()->where('code', $this->videoToolCode)->first();
            $action = ToolAction::query()
                ->where('full_code', "youtube_video.{$this->videoQuality}")
                ->first();
        }

        if (! $tool || ! $action) {
            throw new \RuntimeException('YouTube Tool or ToolAction is missing.');
        }

        return [$tool, $action];
    }

    protected function resetPreview(): void
    {
        $this->isPreviewLoaded = false;
        $this->previewType = null;
        $this->previewTitle = null;
        $this->previewUploader = null;
        $this->previewThumbnail = null;
        $this->previewDurationSec = null;
        $this->previewBillableMinutes = null;
        $this->previewEntriesCount = null;
        $this->previewWebpageUrl = null;
        $this->previewEntries = [];

        $this->creditsCost = 0;
    }

    protected function resetCurrentTelemetry(): void
    {
        $this->currentPhase = null;
        $this->currentStatusMessage = null;
        $this->currentSpeedBps = null;
        $this->currentEtaSec = null;
        $this->currentDownloadedBytes = null;
        $this->currentTotalBytes = null;
        $this->currentElapsedSec = null;
        $this->currentQueuedForSec = null;
        $this->currentProgressUpdatedAt = null;
        $this->currentProgressTitle = null;
        $this->currentProgressFileName = null;
        $this->currentPlaylistIndex = null;
        $this->currentPlaylistCount = null;
        $this->currentLogLines = [];
    }

    protected function defaultTelemetryMessage(?string $status, ?int $queuedForSec = null): ?string
    {
        return match ($status) {
            'queued' => ($queuedForSec ?? 0) >= 30
                ? 'Waiting for the queue worker to pick up this job.'
                : 'Queued and waiting to start.',
            'running' => 'Worker is processing the download.',
            'saving' => 'Saving the finished file.',
            'done' => 'Download completed successfully.',
            'failed' => 'Download failed.',
            default => null,
        };
    }

    protected function seedCurrentTelemetryFromJob(MlJob $job): void
    {
        $status = (string) $job->status;
        $queuedForSec = $status === 'queued' && $job->created_at
            ? now()->diffInSeconds($job->created_at)
            : null;
        $elapsedSec = in_array($status, ['running', 'saving'], true) && $job->started_at
            ? now()->diffInSeconds($job->started_at)
            : null;

        $this->resetCurrentTelemetry();
        $this->currentPhase = match ($status) {
            'queued' => 'queued',
            'running' => 'initializing',
            'saving' => 'processing',
            'done' => 'completed',
            'failed' => 'failed',
            default => null,
        };
        $this->currentStatusMessage = (string) ($this->defaultTelemetryMessage($status, $queuedForSec) ?? '');
        $this->currentQueuedForSec = $queuedForSec;
        $this->currentElapsedSec = $elapsedSec;
        $this->currentProgressTitle = (string) data_get($job->input, 'title', '');
        $this->currentPlaylistCount = data_get($job->input, 'entries_count') !== null
            ? (int) data_get($job->input, 'entries_count')
            : null;
    }

    protected function hydrateCurrentJobFromDb(): void
    {
        $customerId = auth('app')->id();
        if (! $customerId) {
            return;
        }

        $toolIds = Tool::query()
            ->whereIn('code', [$this->audioToolCode, $this->videoToolCode])
            ->pluck('id')
            ->all();

        $job = MlJob::query()
            ->where('customer_id', $customerId)
            ->when(! empty($toolIds), fn ($q) => $q->whereIn('tool_id', $toolIds))
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

        if (! $job) {
            $this->currentJobId = null;
            $this->currentStatus = null;
            $this->jobFinished = false;
            $this->showJobStatus = false;
            $this->currentProgress = 0;
            $this->resetCurrentTelemetry();

            return;
        }

        $status = (string) $job->status;

        $this->currentJobId = (string) $job->id;
        $this->currentStatus = $status;
        $this->jobFinished = in_array($status, ['done', 'failed', 'deleted'], true);
        $this->seedCurrentTelemetryFromJob($job);

        $this->currentProgress = match ($status) {
            'queued' => 10,
            'running' => 50,
            'saving' => 90,
            'done' => 100,
            'failed' => 100,
            'deleted' => 100,
            default => 0,
        };

        if (! $this->jobFinished) {
            $this->showJobStatus = true;

            return;
        }

        $finishedAt = $job->finished_at;

        $this->showJobStatus =
            $this->dismissedJobStatusFor !== (string) $job->id
            && $finishedAt
            && $finishedAt->gte(now()->subSeconds(3));
    }

    // =========================================================
    // Preview
    // =========================================================
    public function previewUrl(YoutubePreviewService $preview): void
    {
        $this->validateOnly('url', [
            'url' => ['required', 'string', 'max:2000'],
        ]);

        if (! $this->validYouTubeUrl($this->url)) {
            $this->dispatch('alert', type: 'error', message: 'Please enter a valid YouTube URL.');

            return;
        }

        try {
            $data = $preview->preview(trim($this->url));

            $this->isPreviewLoaded = true;
            $this->previewType = (string) ($data['type'] ?? 'video');
            $this->previewTitle = (string) ($data['title'] ?? 'Untitled');
            $this->previewUploader = (string) ($data['uploader'] ?? '');
            $this->previewThumbnail = (string) ($data['thumbnail'] ?? '');
            $this->previewDurationSec = (int) ($data['duration_sec'] ?? 0);
            $this->previewBillableMinutes = isset($data['billable_minutes']) ? (int) $data['billable_minutes'] : null;
            $this->previewEntriesCount = isset($data['entries_count']) ? (int) $data['entries_count'] : null;
            $this->previewWebpageUrl = (string) ($data['webpage_url'] ?? trim($this->url));
            $this->previewEntries = (array) ($data['entries'] ?? []);

            $this->syncCostPreview();

            $this->dispatch('alert', type: 'success', message: 'Preview loaded successfully.');
        } catch (\Throwable $e) {
            Log::warning('YOUTUBE_PREVIEW_FAIL', [
                'url' => $this->url,
                'error' => $e->getMessage(),
            ]);

            $this->resetPreview();
            $this->dispatch('alert', type: 'error', message: 'Could not preview this URL. Please make sure it is a working YouTube video or playlist link.');
        }
    }

    // =========================================================
    // Actions
    // =========================================================
    public function startDownload(
        CreditService $credits,
        JobExecutionLockService $locks
    ): void {
        $this->normalizeDownloadOptions();
        $this->showJobStatus = true;
        $this->hydrateCurrentJobFromDb();
        if ($this->isProcessing() || $this->currentActiveJobsCount() >= 1) {
            $this->dispatch('alert', type: 'warning', message: 'A YouTube download job is already in progress.');

            return;
        }

        $this->validate();

        if (! $this->isPreviewLoaded) {
            $this->dispatch('alert', type: 'error', message: 'Please preview the URL first.');

            return;
        }

        $customer = auth('app')->user();
        if (! $customer) {
            $this->dispatch('alert', type: 'error', message: 'You must be logged in.');

            return;
        }

        [$tool, $action] = $this->findToolAndAction();

        if ($this->creditsCost <= 0) {
            $this->dispatch('alert', type: 'error', message: 'Could not calculate billing for this download.');

            return;
        }

        $jobId = (string) Str::uuid();
        $workerMode = $this->previewType === 'playlist' ? 'playlist' : $this->downloadMode;
        $resolvedFormat = $this->downloadMode === 'video' ? 'mp4' : $this->audioFormat;

        try {
            DB::transaction(function () use ($jobId, $tool, $action, $customer, $resolvedFormat, $workerMode) {
                MlJob::create([
                    'id' => $jobId,
                    'customer_id' => (int) $customer->id,
                    'tool_id' => (int) $tool->id,
                    'tool_action_id' => (int) $action->id,
                    'job_kind' => 'youtube_download',
                    'status' => 'queued',
                    'provider' => 'local_worker',
                    'provider_job_id' => null,
                    'credits_charged' => 0,
                    'input' => [
                        'url' => trim($this->url),
                        'mode' => $this->downloadMode,
                        'worker_mode' => $workerMode,
                        'format' => $resolvedFormat,
                        'quality' => $this->videoQuality,
                        'include_thumb' => $this->includeThumbnail,
                        'title' => $this->previewTitle,
                        'uploader' => $this->previewUploader,
                        'thumbnail' => $this->previewThumbnail,
                        'duration_sec' => $this->previewDurationSec,
                        'billable_minutes' => $this->previewBillableMinutes,
                        'entries_count' => $this->previewEntriesCount,
                        'preview_type' => $this->previewType,
                    ],
                    'output' => null,
                    'error' => null,
                    'started_at' => null,
                    'finished_at' => null,
                    'storage_in_bytes' => 0,
                    'storage_out_bytes' => 0,
                ]);
            }, 3);

            $lock = $locks->acquireLock(
                scope: 'youtube_download',
                customerId: (int) $customer->id,
                jobId: $jobId,
                session: app(SessionContract::class),
                userAgent: request()->userAgent(),
                ip: request()->ip(),
                ttlSeconds: 7800
            );

            if (is_array($lock) && array_key_exists('ok', $lock) && ! ($lock['ok'] ?? false)) {
                throw new \RuntimeException((string) ($lock['message'] ?? 'Could not acquire YouTube lock.'));
            }

            $credits->charge((int) $customer->id, $this->creditsCost, 'youtube_download_charge', [
                'related_type' => 'ml_job',
                'related_id' => $jobId,
                'tool_action' => (string) $action->full_code,
                'mode' => $this->downloadMode,
                'worker_mode' => $workerMode,
                'preview_type' => $this->previewType,
                'format' => $resolvedFormat,
                'quality' => $this->videoQuality,
            ]);

            MlJob::query()->where('id', $jobId)->update([
                'credits_charged' => (int) $this->creditsCost,
                'updated_at' => now(),
            ]);

            ProcessYoutubeDownloadJob::dispatch($jobId);

            $this->currentJobId = $jobId;
            $this->currentStatus = 'queued';
            $this->jobFinished = false;
            $this->currentProgress = 10;
            $this->showJobStatus = true;
            $this->resetCurrentTelemetry();
            $this->currentPhase = 'queued';
            $this->currentStatusMessage = 'Queued and waiting to start.';
            $this->currentQueuedForSec = 0;
            $this->currentProgressTitle = (string) ($this->previewTitle ?? '');
            $this->currentPlaylistCount = $this->previewEntriesCount;

            $this->dispatch('header:refresh');
            $this->dispatch('customerPlanUpdated');
            $this->dispatch('youtube-downloads-refresh');
            $this->dispatch('alert', type: 'info', message: 'YouTube download job started.');

            $this->syncWallet();
        } catch (\Throwable $e) {
            Log::warning('YOUTUBE_START_FAIL', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            MlJob::query()->where('id', $jobId)->update([
                'status' => 'failed',
                'error' => ['message' => $e->getMessage()],
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            if (method_exists($locks, 'releaseLock')) {
                $locks->releaseLock($jobId);
            }

            $this->dispatch('alert', type: 'error', message: $e->getMessage());
            $this->syncWallet();
            $this->hydrateCurrentJobFromDb();
        }
    }

    public function pollJob(YoutubeJobSyncService $sync): void
    {
        if (! $this->currentJobId) {
            return;
        }

        $job = MlJob::query()
            ->with('tool')
            ->where('id', $this->currentJobId)
            ->where('customer_id', auth('app')->id())
            ->first();

        if (! $job) {
            return;
        }

        try {
            $result = $sync->sync($job);
            $done = ($result['done'] ?? false) === true;
            $failed = ($result['failed'] ?? false) === true;
            $terminal = $done || $failed;

            $this->currentStatus = (string) ($result['status'] ?? $this->currentStatus);
            $this->currentProgress = (int) ($result['progress'] ?? $this->currentProgress);
            $phase = trim((string) ($result['phase'] ?? ''));
            $message = trim((string) ($result['message'] ?? ''));

            if ($phase !== '') {
                $this->currentPhase = $phase;
            } elseif ($done) {
                $this->currentPhase = 'completed';
            } elseif ($failed) {
                $this->currentPhase = 'failed';
            }

            if ($message !== '') {
                $this->currentStatusMessage = $message;
            }

            $this->currentQueuedForSec = isset($result['queued_for_sec']) ? (int) $result['queued_for_sec'] : null;

            if (isset($result['elapsed_sec']) && $result['elapsed_sec'] !== null) {
                $this->currentElapsedSec = (int) $result['elapsed_sec'];
            } elseif (! $terminal) {
                $this->currentElapsedSec = null;
            }

            if (isset($result['speed_bps']) && $result['speed_bps'] !== null) {
                $this->currentSpeedBps = (float) $result['speed_bps'];
            } elseif (! $terminal) {
                $this->currentSpeedBps = null;
            }

            if (isset($result['eta_sec']) && $result['eta_sec'] !== null) {
                $this->currentEtaSec = (int) $result['eta_sec'];
            } elseif (! $terminal) {
                $this->currentEtaSec = null;
            }

            if (isset($result['downloaded_bytes']) && $result['downloaded_bytes'] !== null) {
                $this->currentDownloadedBytes = (int) $result['downloaded_bytes'];
            } elseif (! $terminal) {
                $this->currentDownloadedBytes = null;
            }

            if (isset($result['total_bytes']) && $result['total_bytes'] !== null) {
                $this->currentTotalBytes = (int) $result['total_bytes'];
            } elseif (! $terminal) {
                $this->currentTotalBytes = null;
            }

            $updatedAt = trim((string) ($result['updated_at'] ?? ''));
            if ($updatedAt !== '') {
                $this->currentProgressUpdatedAt = $updatedAt;
            } elseif (! $terminal) {
                $this->currentProgressUpdatedAt = null;
            }

            $title = trim((string) ($result['title'] ?? ''));
            if ($title !== '') {
                $this->currentProgressTitle = $title;
            }

            $fileName = trim((string) ($result['file_name'] ?? ''));
            if ($fileName !== '') {
                $this->currentProgressFileName = $fileName;
            } elseif (! $terminal) {
                $this->currentProgressFileName = null;
            }

            if (isset($result['playlist_index']) && $result['playlist_index'] !== null) {
                $this->currentPlaylistIndex = (int) $result['playlist_index'];
            } elseif (! $terminal) {
                $this->currentPlaylistIndex = null;
            }

            if (isset($result['playlist_count']) && $result['playlist_count'] !== null) {
                $this->currentPlaylistCount = (int) $result['playlist_count'];
            }

            if (isset($result['log_lines']) && is_array($result['log_lines'])) {
                if ($result['log_lines'] !== [] || ! $terminal) {
                    $this->currentLogLines = $result['log_lines'];
                }
            }

            if ($done) {
                $this->jobFinished = true;
                $this->showJobStatus = true;

                $this->dispatch('customerPlanUpdated');
                $this->dispatch('youtube-downloads-refresh');
                $this->dispatch('header:refresh');
                $this->dispatch('youtube-download-ready', url: route('app.renders.youtube.download', [
                    'locale' => app()->getLocale(),
                    'jobId' => (string) $job->id,
                ]));
                $this->dispatch('alert', type: 'success', message: 'Download completed. Browser download is ready.');
            }

            if ($failed) {
                $this->jobFinished = true;
                $this->showJobStatus = true;

                $msg = (string) ($result['message'] ?? 'Download failed.');
                $this->dispatch('header:refresh');
                $this->dispatch('alert', type: 'error', message: $msg);
            }
        } catch (\Throwable $e) {
            Log::warning('YOUTUBE_POLL_FAIL', [
                'job_id' => $this->currentJobId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function hideJobStatus(): void
    {
        if ($this->currentJobId) {
            $this->dismissedJobStatusFor = $this->currentJobId;
            session(['youtube.dismissed_job_status_for' => $this->currentJobId]);
        }

        $this->showJobStatus = false;
        $this->currentJobId = null;
        $this->currentStatus = null;
        $this->jobFinished = false;
        $this->currentProgress = 0;
        $this->resetCurrentTelemetry();
    }

    public function openEliminateModal(): void
    {
        $this->showEliminateModal = true;
    }

    public function closeEliminateModal(): void
    {
        $this->showEliminateModal = false;
    }

    public function eliminateCurrentJob(JobExecutionLockService $locks): void
    {
        $this->closeEliminateModal();

        if (! $this->currentJobId) {
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
            ]);

            if (method_exists($locks, 'releaseLock')) {
                $locks->releaseLock((string) $job->id);
            }
        }

        $this->currentJobId = null;
        $this->currentStatus = null;
        $this->jobFinished = true;
        $this->showJobStatus = false;
        $this->currentProgress = 0;
        $this->resetCurrentTelemetry();

        $this->dispatch('header:refresh');
        $this->dispatch('youtube-downloads-refresh');
        $this->dispatch('alert', type: 'warning', message: 'Current YouTube job eliminated. Credits were not refunded.');
    }

    public function deleteDownload(string $jobId, YoutubeJobSyncService $sync): void
    {
        $job = MlJob::query()
            ->where('id', $jobId)
            ->where('customer_id', auth('app')->id())
            ->firstOrFail();

        try {
            $sync->deleteFinishedDownload($job);
        } catch (\Throwable $e) {
            Log::warning('YOUTUBE_DELETE_FAIL', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            $this->dispatch('alert', type: 'error', message: 'Could not delete this download.');
            $this->refreshUi();

            return;
        }

        $this->dispatch('header:refresh');
        $this->dispatch('youtube-downloads-refresh');
        $this->dispatch('alert', type: 'success', message: 'Download deleted.');
    }

    public function reuseDownload(string $jobId): void
    {
        $toolIds = Tool::query()
            ->whereIn('code', [$this->audioToolCode, $this->videoToolCode])
            ->pluck('id')
            ->all();

        $job = MlJob::query()
            ->where('id', $jobId)
            ->where('customer_id', auth('app')->id())
            ->when(! empty($toolIds), fn ($q) => $q->whereIn('tool_id', $toolIds))
            ->firstOrFail();

        $this->url = (string) data_get($job->input, 'url', '');
        $this->downloadMode = (string) data_get($job->input, 'mode', 'audio');
        $this->audioFormat = (string) data_get($job->input, 'format', 'mp3');
        $this->videoQuality = (string) data_get($job->input, 'quality', 'p720');
        $this->includeThumbnail = (bool) data_get($job->input, 'include_thumb', false);

        $this->normalizeDownloadOptions();
        $this->resetPreview();
        $this->syncCostPreview();

        $this->dispatch('alert', type: 'info', message: 'Link loaded. Preview it again to start a new download.');
    }

    public function resetForm(): void
    {
        $this->url = '';
        $this->downloadMode = 'audio';
        $this->audioFormat = 'mp3';
        $this->videoQuality = 'p720';
        $this->includeThumbnail = false;

        $this->resetPreview();
    }

    public function humanDuration(?int $sec): string
    {
        $sec = (int) $sec;

        if ($sec <= 0) {
            return '—';
        }

        $h = floor($sec / 3600);
        $m = floor(($sec % 3600) / 60);
        $s = $sec % 60;

        if ($h > 0) {
            return sprintf('%02d:%02d:%02d', $h, $m, $s);
        }

        return sprintf('%02d:%02d', $m, $s);
    }

    public function humanBytes(?int $bytes): string
    {
        $bytes = (int) $bytes;

        if ($bytes <= 0) {
            return '—';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return number_format($bytes, $i === 0 ? 0 : 2).' '.$units[$i];
    }

    public function humanRate(?float $bytesPerSecond): string
    {
        $speed = (float) $bytesPerSecond;

        if ($speed <= 0) {
            return '-';
        }

        $units = ['B/s', 'KB/s', 'MB/s', 'GB/s'];
        $i = 0;

        while ($speed >= 1024 && $i < count($units) - 1) {
            $speed /= 1024;
            $i++;
        }

        return number_format($speed, $i === 0 ? 0 : 2).' '.$units[$i];
    }

    public function humanPhase(?string $phase): string
    {
        $value = trim((string) $phase);

        if ($value === '') {
            return '-';
        }

        return (string) Str::of(str_replace('_', ' ', $value))->title();
    }

    public function humanLogTime(?string $timestamp): string
    {
        $value = trim((string) $timestamp);

        if ($value === '') {
            return '-';
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)
                ->timezone(config('app.timezone'))
                ->format('H:i:s');
        } catch (\Throwable) {
            return $value;
        }
    }

    public function render()
    {
        return view('app.pages.youtube.⚡app-youtube-downloader');
    }
};

?>

<div
    id="youtube-page-root"
    x-data="youtubeDownloaderFormCache()"
    x-init="init()"
>
    @if($currentJobId && !$jobFinished)
        <div wire:poll.keep-alive.3000ms="pollJob"></div>
    @endif

    @php
        $status = strtoupper($currentStatus ?? 'IDLE');
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
        $phaseText = $this->humanPhase($currentPhase);
        $speedText = $currentSpeedBps !== null ? $this->humanRate($currentSpeedBps) : '-';
        $etaText = $currentEtaSec !== null ? $this->humanDuration($currentEtaSec) : '-';
        $elapsedText = $currentElapsedSec !== null ? $this->humanDuration($currentElapsedSec) : '-';
        $queuedText = $currentQueuedForSec !== null ? $this->humanDuration($currentQueuedForSec) : '-';
        $downloadedText = $currentDownloadedBytes !== null ? $this->humanBytes($currentDownloadedBytes) : '-';
        $totalText = $currentTotalBytes !== null ? $this->humanBytes($currentTotalBytes) : '-';
        $lastUpdateText = $currentProgressUpdatedAt ? $this->humanLogTime($currentProgressUpdatedAt) : '-';
        $customerLogLines = is_array($currentLogLines ?? null) ? $currentLogLines : [];
        $currentDownloadUrl = $currentJobId && $currentStatus === 'done'
            ? route('app.renders.youtube.download', ['locale' => app()->getLocale(), 'jobId' => $currentJobId])
            : null;
    @endphp

    <div class="row g-3">
        <div class="col-12">
            @if($showJobStatus && $currentJobId && $currentStatus)
                <div class="glass-load {{ $glassClass }} p-3">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                        <div>
                            <div class="fw-semibold">YouTube download status</div>
                            @if($currentProgressTitle)
                                <div class="small mt-1">{{ $currentProgressTitle }}</div>
                            @endif
                            <div class="small text-muted">Job ID: {{ $currentJobId ?: '—' }}</div>
                        </div>
                        <span class="badge text-bg-{{ $badge }} fs-6 px-3 py-2">{{ $status }}</span>
                    </div>

                    <div class="progress" role="progressbar"
                         aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100"
                         style="height:8px;">
                        <div class="progress-bar progress-bar-striped {{ !$jobFinished ? 'progress-bar-animated' : '' }} bg-{{ $badge }}"
                             style="width: {{ $progress }}%"></div>
                    </div>

                    <div class="d-flex align-items-center justify-content-between mt-2 small flex-wrap gap-2">
                        <span class="text-muted">{{ $progress }}%</span>
                        @if($currentStatusMessage)
                            <span class="text-muted">{{ $currentStatusMessage }}</span>
                        @endif
                        <div class="d-flex align-items-center gap-2">
                            @if($currentDownloadUrl)
                                <a class="btn btn-sm btn-outline-success" href="{{ $currentDownloadUrl }}">
                                    Download To Device
                                </a>
                            @endif
                            @if($jobFinished)
                                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="hideJobStatus">Hide</button>
                            @endif
                        </div>
                    </div>

                    @if($currentDownloadUrl)
                        <div class="small text-muted mt-2">
                            The file is delivered directly to your browser. History only keeps the source YouTube link.
                        </div>
                    @endif

                    <div class="row g-2 mt-2">
                        <div class="col-6 col-lg-3">
                            <div class="border rounded-3 p-2 h-100 bg-dark bg-opacity-50">
                                <div class="small">Phase</div>
                                <div class="fw-semibold">{{ $phaseText }}</div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="border rounded-3 p-2 h-100 bg-dark bg-opacity-50">
                                <div class="small">Speed</div>
                                <div class="fw-semibold">{{ $speedText }}</div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="border rounded-3 p-2 h-100 bg-dark bg-opacity-50">
                                <div class="small">ETA</div>
                                <div class="fw-semibold">{{ $etaText }}</div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="border rounded-3 p-2 h-100 bg-dark bg-opacity-50">
                                <div class="small">Downloaded</div>
                                <div class="fw-semibold">{{ $downloadedText }} / {{ $totalText }}</div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="border rounded-3 p-2 h-100 bg-dark bg-opacity-50">
                                <div class="small">{{ $currentStatus === 'queued' ? 'Queued For' : 'Elapsed' }}</div>
                                <div class="fw-semibold">{{ $currentStatus === 'queued' ? $queuedText : $elapsedText }}</div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="border rounded-3 p-2 h-100 bg-dark bg-opacity-50">
                                <div class="small">Last Update</div>
                                <div class="fw-semibold">{{ $lastUpdateText }}</div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="border rounded-3 p-2 h-100 bg-dark bg-opacity-50">
                                <div class="small">Output File</div>
                                <div class="fw-semibold text-break">{{ $currentProgressFileName ?: '-' }}</div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="border rounded-3 p-2 h-100 bg-dark bg-opacity-50">
                                <div class="text-muted small">Playlist</div>
                                <div class="fw-semibold">
                                    @if($currentPlaylistCount)
                                        {{ (int) ($currentPlaylistIndex ?? 0) }}/{{ (int) $currentPlaylistCount }}
                                    @else
                                        -
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-2">
                            <div class="fw-semibold small">Customer log</div>
                            <div class="text-muted small">
                                @if($currentStatus === 'queued')
                                    Queue wait: {{ $queuedText }}
                                @elseif($currentElapsedSec !== null)
                                    Elapsed: {{ $elapsedText }}
                                @endif
                            </div>
                        </div>

                        <div class="border rounded-3 p-2 bg-dark bg-opacity-50" style="max-height: 220px; overflow-y: auto;">
                            @if($customerLogLines === [])
                                <div class="small text-muted">{{ $currentStatusMessage ?: 'Waiting for worker updates.' }}</div>
                            @else
                                <div class="d-flex flex-column gap-2">
                                    @foreach($customerLogLines as $line)
                                        <div class="d-flex align-items-start justify-content-between gap-3 small">
                                            <div style="min-width: 0;">
                                                <span class="me-2">{{ $this->humanLogTime($line['ts'] ?? null) }}</span>
                                                <span>{{ (string) ($line['message'] ?? '') !== '' ? $line['message'] : $this->humanPhase($line['phase'] ?? null) }}</span>
                                            </div>
                                            <div class="text-end flex-shrink-0">
                                                @if(isset($line['percent']) && $line['percent'] !== null)
                                                    <span>{{ (int) $line['percent'] }}%</span>
                                                @endif
                                                @if(isset($line['speed_bps']) && $line['speed_bps'] !== null)
                                                    <span class="ms-2">{{ $this->humanRate((float) $line['speed_bps']) }}</span>
                                                @endif
                                                @if(isset($line['eta_sec']) && $line['eta_sec'] !== null)
                                                    <span class="ms-2">ETA {{ $this->humanDuration((int) $line['eta_sec']) }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
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
                                <strong>YouTube Downloader PRO</strong>
                                <div class="text-muted small">Preview a YouTube link, choose output type, and download through your METKURD job system</div>
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

                                @if($previewDurationSec)
                                    <div class="mini-stat">
                                        <div class="text-muted">Duration</div>
                                        <div class="fw-semibold">{{ $this->humanDuration($previewDurationSec) }}</div>
                                    </div>
                                @endif

                                @if($previewEntriesCount)
                                    <div class="mini-stat">
                                        <div class="text-muted">Entries</div>
                                        <div class="fw-semibold">{{ number_format($previewEntriesCount) }}</div>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">YouTube URL</label>
                                <div class="input-group">
                                    <input
                                        type="text"
                                        class="form-control"
                                        placeholder="https://www.youtube.com/watch?v=..."
                                        wire:model.defer="url"
                                    >
                                    <button
                                        type="button"
                                        class="btn btn-outline-primary"
                                        wire:click="previewUrl"
                                        wire:loading.attr="disabled"
                                        wire:target="previewUrl"
                                    >
                                        <span wire:loading.remove wire:target="previewUrl">Preview</span>
                                        <span wire:loading wire:target="previewUrl">
                                            <span class="spinner-border spinner-border-sm me-1"></span>
                                            Loading...
                                        </span>
                                    </button>
                                </div>

                                @error('url')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror

                                <div class="small text-muted mt-2">
                                    Supported: YouTube video and playlist links
                                </div>
                            </div>

                            @if($isPreviewLoaded)
                                <div class="yt-preview-card p-3 rounded-4 mb-3">
                                    <div class="row g-3 align-items-start">
                                        <div class="col-md-4">
                                            <div class="ratio ratio-16x9 yt-thumb-wrap">
                                                @if($previewThumbnail)
                                                    <img
                                                        src="{{ $previewThumbnail }}"
                                                        alt="Thumbnail"
                                                        class="yt-thumb-img rounded-3"
                                                        loading="lazy"
                                                    >
                                                @else
                                                    <div class="yt-thumb-placeholder rounded-3 d-flex align-items-center justify-content-center">
                                                        <span class="small text-muted">No thumbnail</span>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="col-md-8">
                                            <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                                                <span class="badge text-bg-primary">{{ strtoupper($previewType ?: 'VIDEO') }}</span>

                                                @if($previewDurationSec)
                                                    <span class="badge text-bg-dark">{{ $this->humanDuration($previewDurationSec) }}</span>
                                                @endif

                                                @if($previewEntriesCount)
                                                    <span class="badge text-bg-warning">{{ number_format($previewEntriesCount) }} items</span>
                                                @endif
                                            </div>

                                            <div class="fw-semibold fs-5 lh-sm mb-2">
                                                {{ $previewTitle ?: 'Untitled' }}
                                            </div>

                                            @if($previewUploader)
                                                <div class="small text-muted mb-2">
                                                    Channel: {{ $previewUploader }}
                                                </div>
                                            @endif

                                            @if($previewWebpageUrl)
                                                <div class="small text-break text-muted">
                                                    {{ $previewWebpageUrl }}
                                                </div>
                                            @endif

                                            @if($previewType === 'playlist' && count($previewEntries))
                                                <div class="mt-3">
                                                    <div class="small fw-semibold mb-2">Playlist Preview</div>
                                                    <div class="yt-playlist-list">
                                                        @foreach(array_slice($previewEntries, 0, 8) as $i => $entry)
                                                            <div class="yt-playlist-item">
                                                                <span class="yt-playlist-index">{{ $i + 1 }}</span>
                                                                <span class="text-truncate">{{ $entry['title'] ?? 'Untitled item' }}</span>
                                                            </div>
                                                        @endforeach

                                                        @if(count($previewEntries) > 8)
                                                            <div class="small text-muted mt-2">
                                                                + {{ count($previewEntries) - 8 }} more items
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <hr>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Download Mode</label>
                                    <select class="form-select" wire:model.live="downloadMode">
                                        <option value="audio">Audio</option>
                                        <option value="video">Video</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    @if($downloadMode === 'audio')
                                        <label class="form-label">Audio Format</label>
                                        <select class="form-select" wire:model.live="audioFormat">
                                            <option value="mp3">MP3</option>
                                            <option value="wav">WAV</option>
                                        </select>
                                    @else
                                        <label class="form-label">Video Quality</label>
                                        <select class="form-select" wire:model.live="videoQuality">
                                            <option value="p480">480p</option>
                                            <option value="p720">720p</option>
                                            <option value="p1080">1080p</option>
                                            <option value="p4k">4K</option>
                                        </select>
                                    @endif
                                </div>

                                <div class="col-12">
                                    <div class="form-check">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            wire:model.live="includeThumbnail"
                                            id="youtube-include-thumbnail"
                                        >
                                        <label class="form-check-label" for="youtube-include-thumbnail">
                                            Include thumbnail metadata when available
                                        </label>
                                    </div>
                                </div>
                            </div>

                            @if($creditsCost > 0)
                                <div class="youtube-cost-preview rounded-3 p-3 mt-3 small">
                                    Estimated cost:
                                    <strong>{{ number_format($creditsCost) }} credits</strong>

                                    @if($previewType === 'playlist' && $previewEntriesCount)
                                        for <strong>{{ number_format($previewEntriesCount) }} video(s)</strong>
                                    @elseif($previewDurationSec)
                                        based on <strong>{{ $this->humanDuration($previewDurationSec) }}</strong>
                                    @endif
                                </div>
                            @endif

                            @if($previewType === 'playlist')
                                <div class="small text-muted mt-2">
                                    Playlist links are detected automatically. We will bundle the selected output type for the whole playlist.
                                </div>
                            @endif

                            <div class="d-flex gap-2 mt-4 flex-wrap">
                                <button
                                    class="btn {{ $this->canStartDownload ? 'btn-primary' : 'btn-danger' }}"
                                    wire:click="startDownload"
                                    wire:loading.attr="disabled"
                                    wire:target="startDownload,previewUrl"
                                    @disabled(!$this->canStartDownload)
                                    type="button"
                                >
                                    <span wire:loading.remove wire:target="startDownload,previewUrl">
                                        {{ $this->canStartDownload ? 'Start Download' : ($this->downloadBlockedReason ?? 'Start Download') }}
                                    </span>

                                    <span wire:loading wire:target="startDownload">
                                        <span class="spinner-border spinner-border-sm me-1"></span>
                                        Starting...
                                    </span>
                                </button>

                                <button class="btn btn-outline-secondary" wire:click="resetForm" type="button">
                                    Reset
                                </button>

                                <button
                                    class="btn btn-outline-danger"
                                    wire:click="openEliminateModal"
                                    type="button"
                                    @disabled(!$currentJobId || $jobFinished)
                                >
                                    Eliminate
                                </button>

                                @if($walletBalance < $creditsCost && $creditsCost > 0)
                                    <span class="small text-danger align-self-center">
                                        Not enough credits for this download.
                                    </span>
                                @endif
                            </div>

                            <div class="small text-muted mt-3">
                                Use this only for content you have rights or permission to download.
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
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <strong>Recent Downloads</strong>

                            <button class="btn btn-sm btn-outline-secondary" wire:click="$refresh" type="button">
                                Refresh
                            </button>
                        </div>

                        <div class="card-body">
                            @if($this->downloads->count() === 0)
                                <div class="text-muted">No downloads yet.</div>
                            @else
                                @foreach($this->downloads as $r)
                                    <div
                                        class="render-card yt-download-item {{ $r['is_latest'] ? 'yt-download-item--latest' : '' }} mb-3 p-3 rounded-3 border"
                                        wire:key="youtube-render-{{ $r['id'] }}"
                                    >
                                        <div class="d-flex gap-3">
                                            <div class="yt-mini-thumb">
                                                @if($r['thumbnail'])
                                                    <img src="{{ $r['thumbnail'] }}" alt="thumb" class="rounded-3">
                                                @else
                                                    <div class="yt-mini-thumb-placeholder rounded-3"></div>
                                                @endif
                                            </div>

                                            <div class="min-w-0 flex-grow-1">
                                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                                    <strong class="text-truncate">{{ $r['title'] }}</strong>

                                                    @if($r['is_latest'])
                                                        <span class="badge text-bg-primary">Latest</span>
                                                    @endif

                                                    <span class="badge text-bg-dark">{{ strtoupper($r['mode']) }}</span>

                                                    @if($r['status'] === 'deleted')
                                                        <span class="badge text-bg-secondary">Deleted</span>
                                                    @elseif($r['status'] === 'delete_failed')
                                                        <span class="badge text-bg-danger">Delete Failed</span>
                                                    @endif
                                                </div>

                                                @if($r['uploader'])
                                                    <div class="small text-muted mt-1">
                                                        {{ $r['uploader'] }}
                                                    </div>
                                                @endif

                                                <div class="small text-muted mt-1">
                                                    {{ $r['created_at'] }}

                                                    @if($r['format'])
                                                        • {{ strtoupper($r['format']) }}
                                                    @endif

                                                    @if($r['quality'])
                                                        • {{ strtoupper($r['quality']) }}
                                                    @endif

                                                    @if($r['entries_count'] > 0)
                                                        • {{ number_format($r['entries_count']) }} items
                                                    @endif

                                                    @if($r['duration_sec'] > 0)
                                                        • {{ $this->humanDuration($r['duration_sec']) }}
                                                    @endif
                                                </div>

                                                <div class="small text-muted mt-1">
                                                    Source:
                                                    @if($r['source_url'])
                                                        <a href="{{ $r['source_url'] }}" target="_blank" rel="noopener" class="text-break">
                                                            {{ $r['source_url'] }}
                                                        </a>
                                                    @else
                                                        -
                                                    @endif
                                                </div>

                                                <div class="small text-muted mt-1">
                                                    Last output: {{ $r['download_name'] }}
                                                    @if($r['storage_out_bytes'] > 0)
                                                        • {{ $this->humanBytes($r['storage_out_bytes']) }}
                                                    @endif
                                                    @if($r['credits_charged'] > 0)
                                                        • {{ number_format($r['credits_charged']) }} credits
                                                    @endif
                                                </div>
                                            </div>

                                            <div class="d-flex flex-column gap-2">
                                                <button
                                                    class="btn btn-xs btn-outline-primary"
                                                    wire:click="reuseDownload('{{ $r['id'] }}')"
                                                    type="button"
                                                >
                                                    Use Again
                                                </button>

                                                @if($r['source_url'])
                                                    <a class="btn btn-xs btn-outline-success" href="{{ $r['source_url'] }}" target="_blank" rel="noopener">
                                                        Open Link
                                                    </a>
                                                @else
                                                    <button class="btn btn-xs btn-outline-secondary" type="button" disabled>
                                                        Open Link
                                                    </button>
                                                @endif

                                                @if($r['can_delete'])
                                                    <button
                                                        class="btn btn-xs btn-outline-danger"
                                                        wire:click="deleteDownload('{{ $r['id'] }}')"
                                                        type="button"
                                                    >
                                                        Delete
                                                    </button>
                                                @else
                                                    <button class="btn btn-xs btn-outline-secondary" type="button" disabled>
                                                        Deleted
                                                    </button>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            @endif

                            @if($this->downloads->hasPages())
                                <div class="mt-3">
                                    {{ $this->downloads->links() }}
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
                        <h5 class="modal-title text-danger">Eliminate Current Job</h5>
                        <button type="button" class="btn-close" wire:click="closeEliminateModal"></button>
                    </div>

                    <div class="modal-body">
                        <p class="mb-2">
                            Are you sure you want to eliminate the current YouTube download job?
                        </p>

                        <div class="alert alert-warning mb-0">
                            <strong>Warning:</strong> the credit will <strong>not</strong> be refunded.
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" wire:click="closeEliminateModal">
                            Cancel
                        </button>

                        <button type="button" class="btn btn-danger" wire:click="eliminateCurrentJob">
                            Yes, Eliminate
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal-backdrop fade show"></div>
    @endif
</div>

@push('styles')
<style>
    .youtube-cost-preview{
        background: rgba(var(--bs-info-rgb), .08);
        border: 1px solid rgba(var(--bs-info-rgb), .22);
    }

    .yt-preview-card{
        background: linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.01));
        border: 1px solid rgba(255,255,255,.08);
        box-shadow: 0 10px 30px rgba(0,0,0,.08);
    }

    .yt-thumb-wrap{
        overflow: hidden;
        background: rgba(0,0,0,.05);
    }

    .yt-thumb-img{
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .yt-thumb-placeholder{
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,.06);
    }

    .yt-playlist-list{
        max-height: 220px;
        overflow-y: auto;
        border: 1px solid rgba(255,255,255,.08);
        border-radius: 12px;
        padding: .5rem;
        background: rgba(0,0,0,.02);
    }

    .yt-playlist-item{
        display: flex;
        align-items: center;
        gap: .65rem;
        padding: .45rem .25rem;
        border-bottom: 1px solid rgba(255,255,255,.06);
        font-size: .9rem;
    }

    .yt-playlist-item:last-child{
        border-bottom: 0;
    }

    .yt-playlist-index{
        min-width: 24px;
        height: 24px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: rgba(var(--bs-primary-rgb), .14);
        color: var(--bs-primary);
        font-size: .72rem;
        font-weight: 700;
    }

    .yt-download-item{
        transition: background .15s;
    }

    .yt-download-item:hover{
        background: rgba(var(--bs-primary-rgb), .03);
    }

    .yt-download-item--latest{
        border-left: 3px solid var(--bs-primary);
    }

    .yt-mini-thumb{
        width: 86px;
        min-width: 86px;
        height: 58px;
        overflow: hidden;
        border-radius: 12px;
        background: rgba(0,0,0,.06);
    }

    .yt-mini-thumb img{
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .yt-mini-thumb-placeholder{
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,.06);
    }

    .btn-xs{
        padding: .2rem .45rem;
        font-size: .72rem;
        border-radius: .3rem;
    }
</style>
@endpush

@push('scripts')
<script>
function youtubeDownloaderFormCache() {
    return {
        cacheKey: 'youtube_downloader_form_state_v1',

        url: @entangle('url').live,
        downloadMode: @entangle('downloadMode').live,
        audioFormat: @entangle('audioFormat').live,
        videoQuality: @entangle('videoQuality').live,
        includeThumbnail: @entangle('includeThumbnail').live,

        init() {
            this.restore();

            this.$watch('url', () => this.save());
            this.$watch('downloadMode', () => this.save());
            this.$watch('audioFormat', () => this.save());
            this.$watch('videoQuality', () => this.save());
            this.$watch('includeThumbnail', () => this.save());
        },

        save() {
            try {
                localStorage.setItem(this.cacheKey, JSON.stringify({
                    url: this.url,
                    downloadMode: this.downloadMode,
                    audioFormat: this.audioFormat,
                    videoQuality: this.videoQuality,
                    includeThumbnail: this.includeThumbnail,
                }));
            } catch (_) {}
        },

        restore() {
            try {
                const raw = localStorage.getItem(this.cacheKey);
                if (!raw) return;

                const data = JSON.parse(raw);

                if (data.url !== undefined) this.url = data.url;
                if (data.downloadMode !== undefined) this.downloadMode = data.downloadMode;
                if (data.audioFormat !== undefined) this.audioFormat = data.audioFormat;
                if (data.videoQuality !== undefined) this.videoQuality = data.videoQuality;
                if (data.includeThumbnail !== undefined) this.includeThumbnail = data.includeThumbnail;
            } catch (_) {}
        }
    }
}

document.addEventListener('livewire:init', () => {
    Livewire.on('youtube-download-ready', ({ url }) => {
        if (!url) {
            return;
        }

        const link = document.createElement('a');
        link.href = url;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();

        requestAnimationFrame(() => {
            link.remove();
        });
    });
});
</script>
@endpush
