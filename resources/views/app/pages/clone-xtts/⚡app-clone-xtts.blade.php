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
use Illuminate\Support\Facades\Storage;

use App\Models\Tool;
use App\Models\ToolAction;
use App\Models\MlJob;
use App\Models\CustomerUsage;

use App\Services\Providers\RunPodProvider;
use App\Services\Billing\CreditService;
use App\Services\Storage\CustomerOutputStorage;

use App\Services\XTTS\XttsJobSyncService;
use App\Services\Security\JobExecutionLockService;
new
#[Layout('app::layouts.app')]
class extends Component
{
    use WithPagination;
    use WithFileUploads;

    protected $paginationTheme = 'bootstrap';

    protected string $toolCode = 'clone_tts';
    protected string $actionCode = 'standard';
    protected string $fullActionCode = 'clone_tts.standard';

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
    // =========================================================
    // Inputs
    // =========================================================
    public ?string $text = '';
    public string $language = 'ar';

    public bool $split = true;
    public int $max_words = 25;
    public int $fade_ms = 80;

    public float $temperature = 0.65;
    public int $top_k = 50;
    public float $top_p = 0.8;
    public float $repetition_penalty = 2.0;
    public float $length_penalty = 1.0;
    public float $speed = 1.0;

    // uploaded cloning sample
    public $referenceAudio = null;
    public ?string $referenceAudioName = null;
    public ?int $referenceAudioBytes = null;
    public ?string $referenceAudioMime = null;
    public bool $showEliminateModal = false;
    // =========================================================
    // Presets
    // =========================================================
    public string $selectedPreset = 'balanced';

    public array $presets = [
        'balanced'        => 'Balanced',
        'natural'         => 'Natural Voice',
        'clear'           => 'Clear & Stable',
        'expressive'      => 'Expressive',
        'fast_generation' => 'Fast Generation',
    ];

    public function updatedSelectedPreset(string $preset): void
    {
        $this->applyPreset($preset);
    }

    protected function applyPreset(string $preset): void
    {
        match ($preset) {
            'natural' => $this->setPresetValues(
                temperature: 0.55,
                top_k: 45,
                top_p: 0.82,
                repetition_penalty: 2.0,
                length_penalty: 1.0,
                speed: 1.0,
            ),

            'clear' => $this->setPresetValues(
                temperature: 0.35,
                top_k: 30,
                top_p: 0.70,
                repetition_penalty: 2.4,
                length_penalty: 1.1,
                speed: 0.96,
            ),

            'expressive' => $this->setPresetValues(
                temperature: 0.85,
                top_k: 65,
                top_p: 0.90,
                repetition_penalty: 1.8,
                length_penalty: 1.0,
                speed: 1.02,
            ),

            'fast_generation' => $this->setPresetValues(
                temperature: 0.45,
                top_k: 20,
                top_p: 0.65,
                repetition_penalty: 2.2,
                length_penalty: 0.95,
                speed: 1.12,
            ),

            default => $this->setPresetValues(
                temperature: 0.65,
                top_k: 50,
                top_p: 0.80,
                repetition_penalty: 2.0,
                length_penalty: 1.0,
                speed: 1.0,
            ),
        };
    }

    protected function setPresetValues(
        float $temperature,
        int $top_k,
        float $top_p,
        float $repetition_penalty,
        float $length_penalty,
        float $speed
    ): void {
        $this->temperature = $temperature;
        $this->top_k = $top_k;
        $this->top_p = $top_p;
        $this->repetition_penalty = $repetition_penalty;
        $this->length_penalty = $length_penalty;
        $this->speed = $speed;
    }

    // =========================================================
    // Credits UI
    // =========================================================
    public int $maxPerSubmit = 400;
    public int $walletBalance = 0;
    public int $creditsCost = 0;

    // =========================================================
    // Internal
    // =========================================================
    protected function cloneFolderForCustomer($c): string
    {
        return \App\Support\CustomerFolder::make(
            (int) $c->id,
            $c->profile?->first_name ?? $c->first_name ?? null,
            $c->profile?->last_name ?? $c->last_name ?? null,
            $c->username ?? null
        );
    }

    public int $completedNoAudioTicks = 0;
    public int $rendersRefreshKey = 0;

    #[On('header:refresh')]
    #[On('customerPlanUpdated')]
    #[On('customerStorageUpdated')]
    #[On('clone-xtts-renders-refresh')]
    public function refreshUi(): void
    {
        $this->syncWallet();
        $this->syncCostPreview();
        $this->hydrateCurrentJobFromDb();
        $this->rendersRefreshKey++;
    }

    public function mount(): void
    {
        $this->syncWallet();
        $this->applyPreset($this->selectedPreset);
        $this->syncCostPreview();
        $this->dismissedJobStatusFor = session('clone-xtts.dismissed_job_status_for');
        $this->hydrateCurrentJobFromDb();
    }

    public function updatedText(): void
    {
        $this->syncCostPreview();
    }

    public function updatedLanguage(): void
    {
        $this->syncCostPreview();
    }

    public function updatedReferenceAudio(): void
    {
        $this->validateOnly('referenceAudio');

        if ($this->referenceAudio) {
            $this->referenceAudioName = $this->referenceAudio->getClientOriginalName();
            $this->referenceAudioBytes = (int) $this->referenceAudio->getSize();
            $this->referenceAudioMime = $this->referenceAudio->getMimeType();
        } else {
            $this->referenceAudioName = null;
            $this->referenceAudioBytes = null;
            $this->referenceAudioMime = null;
        }
    }

    public function removeReferenceAudio(): void
    {
        $this->referenceAudio = null;
        $this->referenceAudioName = null;
        $this->referenceAudioBytes = null;
        $this->referenceAudioMime = null;

        $this->dispatch('clone-xtts-reference-audio-cleared');
    }

    #[Computed]
    public function currentChars(): int
    {
        return mb_strlen(trim((string) $this->text));
    }

    #[Computed]
    public function currentWords(): int
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $this->text));
        if ($text === '') {
            return 0;
        }

        $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($parts) ? count($parts) : 0;
    }

    #[Computed]
    public function sliders(): array
    {
        return [
            ['key'=>'temperature','label'=>__('Temperature'),'min'=>0,'max'=>2.5,'step'=>0.01,'val'=>$this->temperature],
            ['key'=>'top_p','label'=>__('Top P'),'min'=>0,'max'=>1,'step'=>0.01,'val'=>$this->top_p],
            ['key'=>'top_k','label'=>__('Top K'),'min'=>0,'max'=>100,'step'=>1,'val'=>$this->top_k],
            ['key'=>'repetition_penalty','label'=>__('Repetition Penalty'),'min'=>1,'max'=>8,'step'=>0.01,'val'=>$this->repetition_penalty],
            ['key'=>'length_penalty','label'=>__('Length Penalty'),'min'=>-5,'max'=>6,'step'=>0.01,'val'=>$this->length_penalty],
            ['key'=>'speed','label'=>__('Speed'),'min'=>0.5,'max'=>2,'step'=>0.01,'val'=>$this->speed],
        ];
    }

    #[Computed]
    public function canGenerate(): bool
    {
        return $this->generateBlockedReason === null;
    }

    #[Computed]
    public function generateBlockedReason(): ?string
    {
        if ($this->isGenerating()) {
            return __('A generation is already in progress on this page.');
        }

        if ($this->currentActiveJobsCount() >= $this->allowedConcurrentJobs()) {
            return __('You reached your concurrent job limit for the current plan.');
        }

        if ($this->currentChars <= 0) {
            return __('Please enter some text.');
        }

        if ($this->currentChars > $this->maxPerSubmit) {
            return __('Text exceeds the max characters per submit.');
        }

        if (!$this->referenceAudio) {
            return __('Please upload a reference voice sample.');
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
    public function renders()
    {
        $this->rendersRefreshKey;

        $customerId = auth('app')->id();
        $locale = app()->getLocale();

        $toolId = Tool::where('code', $this->toolCode)->value('id');

        $paginator = MlJob::query()
            ->where('customer_id', $customerId)
            ->when($toolId, fn ($q) => $q->where('tool_id', $toolId))
            ->where('status', 'done')
            ->orderByDesc('finished_at')
            ->paginate(3);

        $paginator->setCollection(
            $paginator->getCollection()->values()->map(function ($j, $index) use ($locale) {
                $jobId = (string) $j->id;
                $path = (string) data_get($j->output, 'path', '');
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $mime = $ext === 'mp3' ? 'audio/mpeg' : 'audio/wav';

                $fullText = trim((string) data_get($j->input, 'text', ''));
                $snippet = mb_strlen($fullText) > 240
                    ? mb_substr($fullText, 0, 160) . '...'
                    : $fullText;

                return [
                    'id' => $jobId,
                    'reference_name' => data_get($j->input, 'reference_audio_name', 'Uploaded Sample'),
                    'model' => 'MK-CTTS',
                    'created_at' => optional($j->finished_at ?? $j->created_at)->format('Y-m-d H:i'),
                    'full_url' => route('app.renders.clone_xtts.stream', [
                        'locale' => $locale,
                        'jobId' => $jobId,
                    ]) . '?proxy=1',
                    'mime' => $mime,
                    'bytes' => (int) data_get($j->output, 'bytes', 0),
                    'download_url' => route('app.renders.clone_xtts.download', [
                        'locale' => $locale,
                        'jobId' => $jobId,
                    ]),
                    'text' => $fullText,
                    'text_snippet' => $snippet,
                    'words' => $this->wordsCount($fullText),
                    'is_latest' => $index === 0,
                ];
            })
        );

        return $paginator;
    }

    protected function wordsCount(string $text): int
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
        if ($text === '') {
            return 0;
        }

        $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($parts) ? count($parts) : 0;
    }

    protected function syncWallet(): void
    {
        $c = auth('app')->user();
        $wallet = $c?->wallet()->first();

        $subscription = (int) ($wallet?->subscription_balance_credits ?? 0);
        $addon = (int) ($wallet?->addon_balance_credits ?? 0);

        $this->walletBalance = $subscription + $addon;

        $this->maxPerSubmit = 400;

        if (method_exists($c, 'entitlementLimitFor')) {
            $this->maxPerSubmit = (int) ($c->entitlementLimitFor($this->fullActionCode, 'max_chars_per_submit') ?? 400);
        }
    }

    protected function syncCostPreview(): void
    {
        $c = auth('app')->user();
        $chars = $this->currentChars;

        if (!$c || $chars <= 0) {
            $this->creditsCost = 0;
            return;
        }

        if (method_exists($c, 'priceCreditsFor')) {
            $this->creditsCost = (int) $c->priceCreditsFor($this->fullActionCode, [
                'chars' => $chars,
                'metric_code' => 'character',
                'language' => $this->language,
            ]);
            return;
        }

        $this->creditsCost = (int) ceil($chars * 1.2);
    }

    protected function allowedConcurrentJobs(): int
    {
        $c = auth('app')->user();
        $planCode = strtolower((string) ($c?->serviceCode() ?? 'free'));

        return match ($planCode) {
            'student' => 4,
            'pro'     => 8,
            'premium' => 10,
            default   => 2,
        };
    }

    protected function currentActiveJobsCount(): int
    {
        $customerId = auth('app')->id();

        if (!$customerId) {
            return 0;
        }

        $toolId = Tool::query()
            ->where('code', $this->toolCode)
            ->value('id');

        if (!$toolId) {
            return 0;
        }

        return MlJob::query()
            ->where('customer_id', $customerId)
            ->where('tool_id', $toolId)
            ->whereIn('status', ['queued', 'running', 'saving'])
            ->whereNotNull('lock_expires_at')
            ->where('lock_expires_at', '>', now())
            ->count();
    }

    protected function rules(): array
    {
        return [
            'text' => ['required', 'string', 'min:1', 'max:' . $this->maxPerSubmit],
            'referenceAudio' => 'required|file|mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,audio/aac,audio/ogg,audio/webm|max:20480',
            'language' => 'required|string|min:1|max:8',
            'split' => 'boolean',
            'max_words' => 'required|integer|min:5|max:80',
            'fade_ms' => 'required|integer|min:0|max:1000',
            'temperature' => 'required|numeric|min:0|max:2.5',
            'top_k' => 'required|integer|min:0|max:100',
            'top_p' => 'required|numeric|min:0|max:1',
            'repetition_penalty' => 'required|numeric|min:1|max:8',
            'length_penalty' => 'required|numeric|min:-5|max:6',
            'speed' => 'required|numeric|min:0.5|max:2.0',
        ];
    }

    protected function isGenerating(): bool
    {
        return (bool) $this->currentJobId
            && !$this->jobFinished
            && in_array($this->currentStatus, ['queued', 'running', 'saving'], true);
    }

    public function hideJobStatus(): void
    {
        if ($this->currentJobId) {
            $this->dismissedJobStatusFor = $this->currentJobId;
            session(['clone-xtts.dismissed_job_status_for' => $this->currentJobId]);
        }

        $this->showJobStatus = false;
        $this->currentJobId = null;
        $this->providerJobId = null;
        $this->currentStatus = null;
        $this->jobFinished = false;
        $this->currentProgress = 0;
    }

    protected function findToolAndAction(): array
    {
        $tool = Tool::where('code', $this->toolCode)->first();
        $action = ToolAction::where('full_code', $this->fullActionCode)->first();

        if (!$tool || !$action) {
            throw new \RuntimeException("Tool or ToolAction missing ({$this->toolCode} / {$this->fullActionCode}).");
        }

        return [$tool, $action];
    }

    public function postCloneXtts(
        RunPodProvider $runpod,
        CreditService $credits,
        CustomerOutputStorage $storage
    ): void
    {
        $this->showJobStatus = true;
        $c = auth('app')->user();
        $actionCode = $this->fullActionCode;

        $this->hydrateCurrentJobFromDb();

        if ($this->currentActiveJobsCount() >= $this->allowedConcurrentJobs()) {
            $this->dispatch('alert', type: 'warning', message: __('You reached your concurrent job limit for the current plan.'));
            return;
        }

        if (method_exists($c, 'isAllowed') && !$c->isAllowed($actionCode)) {
            $this->dispatch('alert', type: 'error', message: __('Your plan does not allow Clone XTTS.'));
            return;
        }

        if ($this->isGenerating()) {
            $this->dispatch('alert', type: 'warning', message: __('A generation is already in progress.'));
            return;
        }

        $this->validate();

        $text = trim((string) $this->text);
        $chars = $this->currentChars;

        $cost = method_exists($c, 'priceCreditsFor')
            ? (int) $c->priceCreditsFor($actionCode, [
                'chars' => $chars,
                'metric_code' => 'character',
                'language' => $this->language,
            ])
            : (int) ceil($chars * 1.2);

        if ($cost <= 0) {
            $this->dispatch('alert', type: 'error', message: __('Pricing is not configured.'));
            return;
        }

        try {
            $credits->charge((int) $c->id, $cost, 'clone_tts_charge', [
                'related_type' => 'ml_job',
                'related_id'   => null,
                'tool_action'  => $actionCode,
                'chars'        => $chars,
            ]);
        } catch (\Throwable $e) {
            $this->syncWallet();
            $this->dispatch('alert', type: 'error', message: __('Not enough credits.'));
            return;
        }

        [$tool, $action] = $this->findToolAndAction();
        $jobId = (string) Str::uuid();
        $this->dismissedJobStatusFor = null;
        session()->forget('clone-xtts.dismissed_job_status_for');
        $referenceName = $this->referenceAudio?->getClientOriginalName() ?: 'reference_audio';
        $referenceMime = $this->referenceAudio?->getMimeType() ?: 'audio/wav';
        $referenceSize = (int) ($this->referenceAudio?->getSize() ?? 0);

        MlJob::create([
            'id'             => $jobId,
            'customer_id'    => $c->id,
            'tool_id'        => $tool->id,
            'tool_action_id' => $action->id,
            'status'         => 'queued',
            'provider'       => 'runpod',
            'input' => [
                'text' => $text,
                'language' => $this->language,
                'split' => (bool) $this->split,
                'max_words' => (int) $this->max_words,
                'fade_ms' => (int) $this->fade_ms,
                'temperature' => (float) $this->temperature,
                'top_k' => (int) $this->top_k,
                'top_p' => (float) $this->top_p,
                'repetition_penalty' => (float) $this->repetition_penalty,
                'length_penalty' => (float) $this->length_penalty,
                'speed' => (float) $this->speed,
                'reference_audio_name' => $referenceName,
                'reference_audio_mime' => $referenceMime,
                'reference_audio_bytes' => $referenceSize,
            ],
            'credits_charged' => $cost,
            'started_at' => now(),
        ]);

        $lock = app(JobExecutionLockService::class)->acquireCloneLock(
            customerId: (int) $c->id,
            jobId: $jobId,
            session: request()->session(),
            agent: request()->userAgent(),
            ip: request()->ip(),
        );

        if (!($lock['ok'] ?? false)) {
            MlJob::where('id', $jobId)->delete();
            $credits->refund((int) $c->id, $cost, 'clone_tts_refund', [
                'related_type' => 'ml_job',
                'related_id' => $jobId,
                'tool_action' => $actionCode,
                'reason' => 'clone_lock_conflict',
            ]);

            $this->dispatch('alert', type: 'warning', message: $lock['message'] ?? __('Clone XTTS is busy on another device.'));
            return;
        }

        $this->currentJobId = $jobId;
        $this->providerJobId = null;
        $this->currentStatus = 'queued';
        $this->jobFinished = false;
        $this->currentProgress = 10;
        $this->completedNoAudioTicks = 0;
        $this->dispatch('header:refresh');

        try {
            $endpointId = data_get($tool->meta, 'runpod_endpoint_id') ?: config('runpod.endpoints.xtts');
            if (!$endpointId) {
                throw new \RuntimeException(__('Clone XTTS endpoint ID is missing.'));
            }

            $timeout = (int) (data_get($tool->meta, 'runpod_timeout') ?: config('runpod.timeout', 60));

            $folder = $this->cloneFolderForCustomer($c);

            $refExt = strtolower($this->referenceAudio?->getClientOriginalExtension() ?: 'wav');
            if ($refExt === '') {
                $refExt = 'wav';
            }

            $refKey = "renders/{$folder}/clone-tts/{$jobId}/ref.{$refExt}";

            $savedRef = $storage->saveUploadedFileToS3(
                (int) $c->id,
                $this->referenceAudio,
                $refKey,
                [
                    'job_id' => $jobId,
                    'tool' => 'clone_tts',
                    'purpose' => 'reference',
                    'role' => 'speaker_reference',
                    'original_name' => $referenceName,
                ]
            );

            $speakerWavUrl = $storage->temporaryUrl($savedRef['path'], 120, [
                'ResponseContentType' => $savedRef['mime'] ?? $referenceMime,
            ]);

            MlJob::where('id', $jobId)->update([
                'input' => array_merge((array) MlJob::find($jobId)?->input, [
                    'reference_audio_name' => $referenceName,
                    'reference_audio_mime' => $referenceMime,
                    'reference_audio_bytes' => $referenceSize,
                    'reference_audio_disk' => $savedRef['disk'],
                    'reference_audio_path' => $savedRef['path'],
                    'speaker_wav_url' => $speakerWavUrl,
                    'ref_max_sec' => 15,
                ]),
                'storage_in_bytes' => (int) $savedRef['bytes'],
            ]);

            $resp = $runpod->run($endpointId, [
                'text' => $text,
                'language' => $this->language,
                'speaker_wav_url' => $speakerWavUrl,
                'ref_max_sec' => 15,
                'split' => (bool) $this->split,
                'max_words' => (int) $this->max_words,
                'fade_ms' => (int) $this->fade_ms,
                'temperature' => (float) $this->temperature,
                'length_penalty' => (float) $this->length_penalty,
                'repetition_penalty' => (float) $this->repetition_penalty,
                'top_k' => (int) $this->top_k,
                'top_p' => (float) $this->top_p,
                'speed' => (float) $this->speed,
                'out_path' => '/out/out_3.wav',
            ], $timeout);

            $rpId = (string) data_get($resp, 'id', '');
            if ($rpId === '') {
                throw new \RuntimeException(__('RunPod did not return a job ID.'));
            }

            MlJob::where('id', $jobId)->update([
                'status' => 'running',
                'provider_job_id' => $rpId,
            ]);

            $this->providerJobId = $rpId;
            $this->currentStatus = 'running';
            $this->currentProgress = 20;
            $this->dispatch('header:refresh');
            $this->syncWallet();

            $this->dispatch('customerPlanUpdated');
            $this->dispatch('customerStorageUpdated');
            $this->dispatch('clone-xtts-renders-refresh');

            $this->dispatch('clone-xtts-job-started', [
                'jobId' => $jobId,
                'providerJobId' => $rpId,
                'status' => 'running',
                'progress' => 20,
            ]);

            $this->dispatch('alert', type: 'success', message: __('RunPod clone job started.'));
        } catch (\Throwable $e) {
            $this->dispatch('header:refresh');

            $credits->refund((int) $c->id, $cost, 'clone_tts_refund', [
                'related_type' => 'ml_job',
                'related_id'   => $jobId,
                'tool_action'  => $actionCode,
                'reason'       => 'provider_start_failed',
            ]);

            MlJob::where('id', $jobId)->update([
                'status' => 'failed',
                'error' => ['message' => $e->getMessage()],
                'finished_at' => now(),
            ]);

            $failedJob = MlJob::find($jobId);
            $refPath = (string) data_get($failedJob?->input, 'reference_audio_path', '');
            $refBytes = (int) data_get($failedJob?->input, 'reference_audio_bytes', 0);

            if ($refPath !== '') {
                try {
                    $storage->deleteFromS3AndUncount((int) $c->id, $refPath, $refBytes);
                } catch (\Throwable $cleanupError) {
                    Log::warning('CLONE_TTS_REF_CLEANUP_FAIL', [
                        'job_id' => $jobId,
                        'path' => $refPath,
                        'err' => $cleanupError->getMessage(),
                    ]);
                }
            }

            $this->currentStatus = 'failed';
            $this->jobFinished = true;
            $this->currentProgress = 100;
            $this->syncWallet();

            $this->dispatch('header:refresh');
            $this->dispatch('clone-xtts-job-state-clear');
            $this->dispatch('alert', type: 'error', message: __('RunPod failed: :message', ['message' => $e->getMessage()]));
        }
    }

    public function pollJob(XttsJobSyncService $sync): void
    {
        $this->showJobStatus = true;

        if (!$this->currentJobId || $this->jobFinished) {
            return;
        }

        $job = MlJob::query()->with('tool')->find($this->currentJobId);
        if (!$job || !$job->tool) {
            return;
        }

        try {
            $result = $sync->sync($job, $job->tool);

            $this->currentStatus = $result['status'] ?? $this->currentStatus;
            $this->currentProgress = (int) ($result['progress'] ?? $this->currentProgress);
            $this->jobFinished = (bool) (($result['done'] ?? false) || ($result['failed'] ?? false));

            MlJob::query()->where('id', $job->id)->update(['updated_at' => now()]);

            $this->dispatch('clone-xtts-job-state-sync', [
                'jobId' => $this->currentJobId,
                'status' => $this->currentStatus,
                'progress' => $this->currentProgress,
            ]);

            if (!empty($result['done'])) {
                $this->syncWallet();
                $this->dispatch('customerPlanUpdated');
                $this->dispatch('customerStorageUpdated');
                $this->dispatch('clone-xtts-renders-refresh');
                $this->dispatch('clone-xtts-job-completed');
                $this->dispatch('clone-xtts-job-state-clear');
                $this->dispatch('alert', type: 'success', message: __('Done'));
            }

            if (!empty($result['failed'])) {
                $this->dispatch('header:refresh');
                $this->dispatch('clone-xtts-job-state-clear');
                $this->dispatch('alert', type: 'error', message: $result['message'] ?: __('Job failed.'));
            }
        } catch (\Throwable $e) {
            Log::warning('RUNPOD_TTS_STATUS_FAIL', [
                'job_id' => $this->currentJobId,
                'err' => $e->getMessage(),
            ]);

            MlJob::query()->where('id', $this->currentJobId)->update([
                'status' => 'failed',
                'error' => ['message' => __('Polling failed: :message', ['message' => $e->getMessage()])],
                'finished_at' => now(),
            ]);

            $this->currentStatus = 'failed';
            $this->jobFinished = true;
            $this->currentProgress = 100;

            $this->dispatch('header:refresh');
            $this->dispatch('clone-xtts-job-state-clear');
            $this->dispatch('alert', type: 'error', message: __('Polling failed: :message', ['message' => $e->getMessage()]));
        }
    }

    public function deleteRender(string $jobId, XttsJobSyncService $sync): void
    {
        $customerId = auth('app')->id();
        $toolId = Tool::where('code', $this->toolCode)->value('id');

        $job = MlJob::query()
            ->with('tool')
            ->where('id', $jobId)
            ->where('customer_id', $customerId)
            ->when($toolId, fn ($q) => $q->where('tool_id', $toolId))
            ->where('status', 'done')
            ->first();

        if (!$job) {
            $this->dispatch('alert', type: 'error', message: __('Render not found.'));
            return;
        }

        try {
            $sync->deleteFinishedRender($job);
            $this->resetPage();
            $this->rendersRefreshKey++;
            $this->dispatch('customerStorageUpdated');
            $this->dispatch('clone-xtts-renders-refresh');
            $this->dispatch('alert', type: 'success', message: __('Deleted.'));
        } catch (\Throwable $e) {
            $this->dispatch('alert', type: 'error', message: __('Delete failed: :message', ['message' => $e->getMessage()]));
        }
    }

    public function clearText(): void
    {
        $this->text = '';
        $this->syncCostPreview();
    }

    public function resetToDefaults(): void
    {
        $this->text = '';
        $this->language = 'ar';
        $this->split = true;
        $this->max_words = 25;
        $this->fade_ms = 80;
        $this->referenceAudio = null;
        $this->referenceAudioName = null;
        $this->referenceAudioBytes = null;
        $this->referenceAudioMime = null;

        $this->selectedPreset = 'balanced';
        $this->applyPreset($this->selectedPreset);

        $this->syncCostPreview();

        $this->dispatch('clone-xtts-form-state-clear');
    }

    public function openEliminateModal(): void
    {
        if (!$this->currentJobId || $this->jobFinished) {
            $this->dispatch('alert', type: 'warning', message: __('There is no active job to eliminate.'));
            return;
        }

        $this->showEliminateModal = true;
    }

    public function closeEliminateModal(): void
    {
        $this->showEliminateModal = false;
    }

    public function eliminateCurrentJob(): void
    {
        $this->showEliminateModal = false;

        if (!$this->currentJobId) {
            $this->dispatch('alert', type: 'warning', message: __('No current job found.'));
            $this->dispatch('clone-xtts-job-state-clear');
            $this->dispatch('clone-xtts-form-state-clear');
            return;
        }

        $customerId = auth('app')->id();
        $job = MlJob::query()->where('id', $this->currentJobId)->where('customer_id', $customerId)->first();

        if ($job && in_array((string) $job->status, ['queued', 'running', 'saving'], true)) {
            $job->update([
                'status' => 'failed',
                'error' => [
                    'message' => __('Eliminated by customer. Credits are not refundable.'),
                    'type' => 'eliminated_by_customer',
                ],
                'finished_at' => now(),
            ]);

            $refPath = (string) data_get($job->input, 'reference_audio_path', '');
            $refBytes = (int) ((int) $job->storage_in_bytes ?: data_get($job->input, 'reference_audio_bytes', 0));

            try {
                if ($refPath !== '') {
                    app(\App\Services\Storage\CustomerOutputStorage::class)
                        ->deleteFromS3AndUncount((int) $customerId, $refPath, $refBytes);
                }
            } catch (\Throwable $e) {
                Log::warning('CLONE_TTS_ELIMINATE_REF_DELETE_FAIL', [
                    'job_id' => (string) $job->id,
                    'path' => $refPath,
                    'error' => $e->getMessage(),
                ]);
            }

            app(\App\Services\Security\JobExecutionLockService::class)->releaseLock((string) $job->id);
        }

        $this->currentJobId = null;
        $this->providerJobId = null;
        $this->currentStatus = null;
        $this->jobFinished = true;
        $this->showJobStatus = false;
        $this->currentProgress = 0;
        $this->completedNoAudioTicks = 0;

        $this->dispatch('header:refresh');
        $this->dispatch('clone-xtts-job-state-clear');
        $this->dispatch('clone-xtts-form-state-clear');
        $this->dispatch('clone-xtts-renders-refresh');
        $this->dispatch('alert', type: 'warning', message: __('Current job eliminated. Credits were not refunded.'));
    }

    public function render()
    {
        return view('app.pages.clone-xtts.⚡app-clone-xtts');
    }

    protected function hydrateCurrentJobFromDb(): void
    {
        $customerId = auth('app')->id();
        $toolId = Tool::where('code', $this->toolCode)->value('id');

        if (!$customerId || !$toolId) {
            return;
        }

        $job = MlJob::query()
            ->where('customer_id', $customerId)
            ->where('tool_id', $toolId)
            ->where(function ($q) {
                $q->whereIn('status', ['queued', 'running', 'saving'])
                    ->whereNotNull('lock_expires_at')
                    ->where('lock_expires_at', '>', now())
                ->orWhere(function ($q2) {
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

        $this->applyJobStateFromModel($job);
    }

    protected function applyJobStateFromModel(MlJob $job): void
    {
        $status = (string) $job->status;

        $this->currentJobId = (string) $job->id;
        $this->providerJobId = (string) ($job->provider_job_id ?? '');
        $this->currentStatus = $status;

        $this->jobFinished = in_array($status, ['done', 'failed', 'deleted'], true);

        $this->currentProgress = match ($status) {
            'queued'  => 10,
            'running' => 40,
            'saving'  => 90,
            'done'    => 100,
            'failed'  => 100,
            'deleted' => 100,
            default   => 0,
        };

        if (!$this->jobFinished) {
            $this->showJobStatus = true;
            return;
        }

        if ($this->jobFinished) {
            $finishedAt = $job->finished_at;

            $this->showJobStatus =
                $this->dismissedJobStatusFor !== (string) $job->id
                && $finishedAt
                && $finishedAt->gte(now()->subSeconds(3));

            return;
        }
    }
};
?>

<x-slot:title>{{ __('Clone XTTS') }} | {{ __('MET KURD') }}</x-slot:title>

<div id="clone-xtts-page-root">
    @if($currentJobId && !$jobFinished)
        <div wire:poll.4000ms="pollJob"></div>
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
            'queued' => 'warning',
            'running' => 'info',
            'saving' => 'primary',
            'done' => 'success',
            'failed' => 'danger',
            default => 'secondary',
        };

        $glassClass = match($currentStatus) {
            'queued' => 'glass-load--warning',
            'running' => 'glass-load--info',
            'saving' => 'glass-load--primary',
            'done' => 'glass-load--success',
            'failed' => 'glass-load--danger',
            default => 'glass-load--secondary',
        };

        $progress = (int) ($currentProgress ?? 0);
    @endphp

    <div class="row g-3">
        <div class="col-12">
            @if($showJobStatus && $currentJobId && $currentStatus)
                <div class="glass-load {{ $glassClass }} p-3">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                        <div>
                            <div class="fw-semibold">{{ __('XTTS Job Status') }}</div>
                            <div class="small text-muted">{{ __('Job ID:') }} {{ $currentJobId ?: '-' }}</div>
                        </div>
                        <span class="badge text-bg-{{ $badge }}">{{ $status }}</span>
                    </div>

                    <div class="progress" role="progressbar" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar progress-bar-striped {{ !$jobFinished ? 'progress-bar-animated' : '' }} bg-{{ $badge }}" style="width: {{ $progress }}%"></div>
                    </div>

                    <div class="d-flex align-items-center justify-content-between mt-2 small">
                        <span>{{ $progress }}%</span>
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
                                <strong>{{ __('MK-CTTS (MET KURDISH CLONE TEXT-TO-SPEECH)') }}</strong>
                                <div class="text-muted small">{{ __('Upload your voice sample and generate speech in the same style') }}</div>
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
                                <div class="mini-stat">
                                    <div class="text-muted">{{ __('Max/Submit') }}</div>
                                    <div class="fw-semibold">{{ number_format($maxPerSubmit) }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">{{ __('Reference Voice Sample') }}</label>

                                <div wire:ignore>
                                    <input
                                        type="file"
                                        id="clone-reference-audio-pond"
                                        accept=".wav,.mp3,.m4a,.aac,.ogg,.webm,audio/*"
                                    >
                                </div>

                                <div class="small text-muted mt-2">
                                    {{ __('Recommended: clean speech, 10-30 seconds, low background noise.') }}
                                </div>

                                <div wire:loading wire:target="referenceAudio" class="small text-primary mt-2">
                                    {{ __('Uploading sample...') }}
                                </div>

                                @if($referenceAudioName)
                                    <div class="border rounded p-2 mt-2 bg-success-subtle">
                                        <div class="fw-semibold small">{{ $referenceAudioName }}</div>
                                        <div class="small text-muted">
                                            {{ $referenceAudioMime ?: 'audio/*' }}
                                            @if($referenceAudioBytes) | {{ __('Size: :bytes bytes', ['bytes' => number_format($referenceAudioBytes)]) }}
                                            @endif
                                        </div>

                                        <div class="mt-2">
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-danger"
                                                wire:click="removeReferenceAudio"
                                            >
                                                {{ __('Remove sample') }}
                                            </button>
                                        </div>
                                    </div>
                                @endif

                                @error('referenceAudio')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            <hr>

                            <div class="mt-3">
                                <label class="form-label">{{ __('Text') }}</label>

                                <textarea
                                    class="form-control"
                                    rows="6"
                                    wire:model.live.debounce.250ms="text"
                                    placeholder="{{ __('Write a text') }}"
                                    dir="rtl"
                                ></textarea>

                                <div class="d-flex justify-content-between align-items-center mt-2 flex-wrap gap-2">
                                    <div class="d-flex gap-3 small">
                                        <span class="text-muted">{{ __('Chars:') }} <strong>{{ $this->currentChars }}</strong></span>
                                        <span class="text-muted">{{ __('Words:') }} <strong>{{ $this->currentWords }}</strong></span>
                                        <span class="text-muted">{{ __('Credits:') }} <strong>{{ $creditsCost }}</strong></span>
                                    </div>

                                    <button class="btn btn-sm btn-link p-0" wire:click="clearText" type="button">
                                        {{ __('Clear') }}
                                    </button>
                                </div>

                                @error('text')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            <hr>

                            <div class="row g-3 align-items-end">
                                <div class="col-md-4">
                                    <label class="form-label">{{ __('Preset') }}</label>
                                    <select class="form-select" wire:model.live="selectedPreset">
                                        @foreach($presets as $k => $v)
                                            <option value="{{ $k }}">{{ __($v) }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                {{-- <div class="col-md-4">
                                    <label class="form-label">Language</label>
                                    <input type="text"
                                           class="form-control"
                                           wire:model.live.debounce.300ms="language"
                                           maxlength="8"
                                           placeholder="ar">
                                    @error('language')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div> --}}

                                <div class="col-md-2">
                                    <label class="form-label">{{ __('Max Words') }}</label>
                                    <input type="number" class="form-control" wire:model.live="max_words" min="5" max="80">
                                    @error('max_words')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-2">
                                    <label class="form-label">{{ __('Fade (ms)') }}</label>
                                    <input type="number" class="form-control" wire:model.live="fade_ms" min="0" max="1000">
                                    @error('fade_ms')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-12">
                                    <div class="form-check form-switch mt-2">
                                        <input class="form-check-input" type="checkbox" id="splitSwitchCloneXTTS" wire:model.live="split">
                                        <label class="form-check-label" for="splitSwitchCloneXTTS">{{ __('Split long text automatically') }}</label>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mt-1">
                                @foreach($this->sliders as $s)
                                    <div
                                        class="col-md-6"
                                        wire:key="clone-slider-{{ $s['key'] }}-{{ md5((string) $s['val']) }}"
                                        x-data="{
                                            key: '{{ $s['key'] }}',
                                            val: @js($s['val']),
                                            min: {{ $s['min'] }},
                                            max: {{ $s['max'] }},
                                            step: {{ $s['step'] }},
                                            debounceTimer: null,
                                            updateLivewire(v) {
                                                clearTimeout(this.debounceTimer);
                                                this.debounceTimer = setTimeout(() => {
                                                    $wire.set(this.key, this.step < 1 ? parseFloat(v) : parseInt(v));
                                                }, 180);
                                            },
                                            get displayVal() {
                                                return parseFloat(this.val).toFixed(this.step < 1 ? 2 : 0);
                                            }
                                        }"
                                    >
                                        <div class="d-flex justify-content-between align-items-center">
                                            <label class="form-label mb-1">{{ $s['label'] }}</label>
                                            <span class="badge text-bg-light tts-badge" x-text="displayVal"></span>
                                        </div>

                                        <div class="d-flex justify-content-between small text-muted" style="margin-top:-2px;">
                                            <span>{{ $s['min'] }}</span>
                                            <span>{{ $s['max'] }}</span>
                                        </div>

                                        <div class="position-relative">
                                            <input
                                                type="range"
                                                class="form-range tts-range"
                                                :min="min"
                                                :max="max"
                                                :step="step"
                                                x-model="val"
                                                @input="updateLivewire($event.target.value)"
                                            />
                                        </div>

                                        @error($s['key'])
                                            <div class="text-danger small">{{ $message }}</div>
                                        @enderror
                                    </div>
                                @endforeach
                            </div>

                            <div class="d-flex gap-2 mt-4 flex-wrap">
                                <button
                                    class="btn {{ $this->canGenerate ? 'btn-primary' : 'btn-danger' }}"
                                    wire:click="postCloneXtts"
                                    wire:loading.attr="disabled"
                                    wire:target="postCloneXtts,referenceAudio"
                                    @disabled(!$this->canGenerate)
                                    type="button"
                                    id="btn-clone-xtts-generate"
                                >
                                    <span wire:loading.remove wire:target="postCloneXtts,referenceAudio">
                                        {{ $this->canGenerate ? __('Generate') : ($this->generateBlockedReason ?? __('Generate')) }}
                                    </span>

                                    <span wire:loading wire:target="referenceAudio">
                                        <span class="spinner-border spinner-border-sm me-1"></span>
                                        {{ __('Uploading sample...') }}
                                    </span>

                                    <span wire:loading wire:target="postCloneXtts">
                                        <span class="spinner-border spinner-border-sm me-1"></span>
                                        {{ __('Starting...') }}
                                    </span>
                                </button>

                                <button class="btn btn-outline-secondary" wire:click="resetToDefaults" type="button">
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
                                        {{ __('Not enough credits for this generation.') }}
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
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <strong>{{ __('Recent Renders') }}</strong>
                            <button class="btn btn-sm btn-outline-secondary" wire:click="$refresh" type="button">
                                {{ __('Refresh') }}
                            </button>
                        </div>

                        <div class="card-body">
                            @if($this->renders->count() === 0)
                                <div class="text-muted">{{ __('No renders yet.') }}</div>
                            @else
                                @foreach($this->renders as $r)
                                    <div
                                        class="border rounded p-2 mb-2 render-card"
                                        wire:key="clone-xtts-render-{{ $r['id'] }}"
                                        id="clone-render-card-{{ $r['id'] }}"
                                    >
                                        <div class="d-flex justify-content-between gap-2">
                                            <div>
                                                <div class="small text-muted">
                                                    {{ __(':created | :model | :reference', ['created' => $r['created_at'], 'model' => $r['model'], 'reference' => $r['reference_name']]) }}
                                                </div>
                                                <div class="small text-muted">
                                                    {{ __('Words: :words | Bytes: :bytes', ['words' => $r['words'], 'bytes' => number_format($r['bytes'])]) }}
                                                </div>
                                            </div>

                                            <div class="text-end">
                                                <button class="btn btn-sm btn-outline-danger"
                                                        wire:click="deleteRender('{{ $r['id'] }}')"
                                                        wire:loading.attr="disabled"
                                                        wire:target="deleteRender('{{ $r['id'] }}')"
                                                        type="button">
                                                    {{ __('Delete') }}
                                                </button>
                                            </div>
                                        </div>

                                        <div class="mt-2 small">{{ $r['text_snippet'] }}</div>

                                        <div class="mt-2" wire:ignore>
                                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                                <span class="small text-muted" id="clone-xtts-time-{{ $r['id'] }}">--:-- / --:--</span>

                                                <div class="btn-group btn-group-sm">
                                                    <button type="button"
                                                            class="btn btn-outline-primary btn-clone-xtts-preview"
                                                            data-job="{{ $r['id'] }}"
                                                            data-url="{{ $r['full_url'] }}"
                                                            data-latest="{{ $r['is_latest'] ? '1' : '0' }}"
                                                            data-preload-rank="{{ $loop->index }}">
                                                        <i class="fa fa-play me-1"></i> {{ __('Play/Pause') }}
                                                    </button>

                                                    <button type="button"
                                                            class="btn btn-outline-secondary btn-clone-xtts-stop"
                                                            data-job="{{ $r['id'] }}">
                                                        <i class="fa fa-stop me-1"></i> {{ __('Stop') }}
                                                    </button>
                                                </div>
                                            </div>

                                            <div id="clone-xtts-wrap-{{ $r['id'] }}" class="mt-1">
                                                <div id="clone-xtts-ph-{{ $r['id'] }}" class="border rounded bg-dark" style="height:90px; opacity:.25;"></div>
                                                <div id="clone-xtts-wave-{{ $r['id'] }}" class="border rounded" style="height:90px; display:none;"></div>
                                            </div>

                                            <div class="mt-2">
                                                <a class="btn btn-sm btn-outline-primary"
                                                   href="{{ $r['download_url'] }}"
                                                   target="_blank"
                                                   rel="noopener">
                                                    {{ __('Download') }}
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach

                                <div class="mt-3">
                                    {{ $this->renders->links(data: ['scrollTo' => false]) }}
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

@endpush
@push('scripts')
<script src="https://unpkg.com/filepond-plugin-file-validate-type/dist/filepond-plugin-file-validate-type.min.js"></script>
<script src="https://unpkg.com/filepond-plugin-file-validate-size/dist/filepond-plugin-file-validate-size.min.js"></script>
<script src="https://unpkg.com/filepond@^4/dist/filepond.min.js"></script>

<script>
(function () {
    'use strict';

    if (!window.__CLONE_XTTS_POND__) {
        window.__CLONE_XTTS_POND__ = {
            pond: null,
            booted: false,
            bootTimer: null,
            pluginsRegistered: false,
            listenersBound: false,
            livewireBound: false,
            commitHooked: false,
        };
    }

    const S = window.__CLONE_XTTS_POND__;

    function hasFilePondDeps() {
        return typeof window.FilePond !== 'undefined'
            && typeof window.FilePondPluginFileValidateType !== 'undefined'
            && typeof window.FilePondPluginFileValidateSize !== 'undefined';
    }

    function registerPlugins() {
        if (S.pluginsRegistered) return true;
        if (!hasFilePondDeps()) return false;

        FilePond.registerPlugin(
            FilePondPluginFileValidateType,
            FilePondPluginFileValidateSize
        );

        S.pluginsRegistered = true;
        return true;
    }

    function getCloneComponent() {
        if (!window.Livewire) return null;

        const root = document.getElementById('clone-xtts-page-root');
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

        S.booted = false;
    }

    function bootPond(attempt = 0) {
        const input = document.getElementById('clone-reference-audio-pond');
        const lw = getCloneComponent();
        const maxAttempts = 40;

        if (!input || !lw || !registerPlugins()) {
            if (attempt >= maxAttempts) return;

            S.bootTimer = setTimeout(() => bootPond(attempt + 1), 75);
            return;
        }

        destroyPond();

        S.pond = FilePond.create(input, {
            allowMultiple: false,
            allowReorder: false,
            allowReplace: true,
            dropOnPage: false,
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
                'audio/webm'
            ],
            maxFileSize: '20MB',
            labelIdle: `
                <div class="py-3">
                    <div class="mb-1"><strong>${@js(__('Drag & Drop'))}</strong> ${@js(__('your reference audio here'))}</div>
                    <div class="small text-muted">${@js(__('or'))} <span class="filepond--label-action">${@js(__('Browse'))}</span></div>
                </div>
            `,
            server: {
                process: (fieldName, file, metadata, load, error, progress, abort) => {
                    lw.upload(
                        'referenceAudio',
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
                            lw.removeUpload('referenceAudio', file.name, () => {});
                            abort();
                        }
                    };
                },
                revert: (uniqueFileId, load) => {
                    lw.call('removeReferenceAudio');
                    load();
                }
            }
        });

        S.booted = true;
    }

    function bootCloneFilePondPage() {
        if (S.bootTimer) {
            clearTimeout(S.bootTimer);
            S.bootTimer = null;
        }

        S.bootTimer = setTimeout(() => bootPond(), 0);
    }

    if (!S.listenersBound) {
        S.listenersBound = true;

        document.addEventListener('DOMContentLoaded', bootCloneFilePondPage);
        document.addEventListener('livewire:initialized', bootCloneFilePondPage);
        document.addEventListener('livewire:navigated', bootCloneFilePondPage);
        document.addEventListener('livewire:navigating', destroyPond);
    }

    if (window.Livewire && !S.livewireBound) {
        S.livewireBound = true;

        Livewire.on('clone-xtts-reference-audio-cleared', () => {
            if (S.pond) {
                try { S.pond.removeFiles(); } catch (_) {}
            }
        });

        if (!S.commitHooked && typeof Livewire.hook === 'function') {
            S.commitHooked = true;

            Livewire.hook('commit', ({ succeed }) => {
                succeed(() => {
                    requestAnimationFrame(() => {
                        const input = document.getElementById('clone-reference-audio-pond');
                        const isCurrentPage = !!document.getElementById('clone-xtts-page-root');

                        if (isCurrentPage && input && !S.pond) {
                            bootPond();
                        }
                    });
                });
            });
        }
    }

    bootCloneFilePondPage();
})();
</script>
@endpush
@push('scripts')
<script src="https://unpkg.com/wavesurfer.js@7/dist/wavesurfer.min.js"></script>

<script>
(function () {
    'use strict';

    if (!window.__CLONE_XTTS_WAVE__) window.__CLONE_XTTS_WAVE__ = {};
    const S = window.__CLONE_XTTS_WAVE__;

    S.previewWS     = S.previewWS     || new Map();
    S.previewMeta   = S.previewMeta   || new Map();
    S.previewInit   = S.previewInit   || new Set();
    S.pendingFetch  = S.pendingFetch  || new Map();
    S.eventsBound   = S.eventsBound   || false;
    S.commitHooked  = S.commitHooked  || false;
    S.formWatchBoot = S.formWatchBoot || false;

    const CACHE_NAME    = 'clone-xtts-audio-v1';
    const CACHE_MAX     = 30;
    const PRELOAD_LIMIT = 10;

    const SPA_KEY  = 'clone_xtts_spa_job';
    const FORM_KEY = 'clone_xtts_form_state_v1';

    function safeNumber(value, fallback) {
        const n = Number(value);
        return Number.isFinite(n) ? n : fallback;
    }

    function formatTime(sec) {
        sec = Math.max(0, sec || 0);
        const m = String(Math.floor(sec / 60)).padStart(2, '0');
        const s = String(Math.floor(sec % 60)).padStart(2, '0');
        return `${m}:${s}`;
    }

    function primaryColor(isLatest = false) {
        if (isLatest) return '#dc3545';
        return (getComputedStyle(document.documentElement)
            .getPropertyValue('--bs-primary') || '#0d6efd').trim();
    }

    function buildWaveOptions(container, isLatest = false) {
        const c = primaryColor(isLatest);
        return {
            container,
            height: 90,
            normalize: true,
            responsive: true,
            backend: 'MediaElement',
            waveColor: c,
            progressColor: c,
            cursorColor: c,
        };
    }

    function getLivewireComponent() {
        if (!window.Livewire) return null;

        const root = document.getElementById('clone-xtts-page-root');
        if (!root) return null;

        const wireId = root.getAttribute('wire:id');
        if (!wireId) return null;

        try {
            return window.Livewire.find(wireId);
        } catch (_) {
            return null;
        }
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
                await Promise.all(toDelete.map(k => cache.delete(k)));
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
            if (jobId !== exceptJobId) stopWS(ws);
        });
    }

    function initPreview(jobId, url, isLatest = false) {
        if (S.previewWS.has(jobId)) return S.previewWS.get(jobId);

        const ph = document.getElementById('clone-xtts-ph-' + jobId);
        const wave = document.getElementById('clone-xtts-wave-' + jobId);
        const time = document.getElementById('clone-xtts-time-' + jobId);

        if (!wave || !url) return null;

        if (ph) ph.style.display = '';
        wave.style.display = 'none';

        const ws = WaveSurfer.create(buildWaveOptions(wave, isLatest));

        ws.on('ready', () => {
            if (ph) ph.style.display = 'none';
            wave.style.display = '';
            if (time) time.textContent = `00:00 / ${formatTime(ws.getDuration())}`;
        });

        ws.on('timeupdate', () => {
            if (time) {
                time.textContent = `${formatTime(ws.getCurrentTime())} / ${formatTime(ws.getDuration())}`;
            }
        });

        ws.on('finish', () => {
            try { ws.setTime(0); } catch (_) {}
        });

        ws.on('error', (e) => {
            console.error('[Clone XTTS] WaveSurfer error', jobId, e);
        });

        (async () => {
            try {
                const blobUrl = await getBlobUrl(jobId, url);
                ws.load(blobUrl);
            } catch (e) {
                console.warn('[Clone XTTS] Falling back to direct URL', jobId, e);
                ws.load(url);
            }
        })();

        S.previewWS.set(jobId, ws);
        return ws;
    }

    function bindPreviewButtons() {
        document.querySelectorAll('.btn-clone-xtts-preview[data-job][data-url]').forEach(btn => {
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

        document.querySelectorAll('.btn-clone-xtts-stop[data-job]').forEach(btn => {
            if (btn.dataset.bound === '1') return;
            btn.dataset.bound = '1';

            btn.addEventListener('click', () => {
                stopWS(S.previewWS.get(btn.getAttribute('data-job')));
            });
        });
    }

    async function preloadAndRenderRecentAudio() {
        const buttons = Array.from(
            document.querySelectorAll('.btn-clone-xtts-preview[data-job][data-url]')
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
                S.previewInit.add(jobId);
            } catch (e) {
                console.error('[Clone XTTS] Preload failed', jobId, e);
            }
        }
    }

    function highlightLatestRender() {
        const firstCard = document.querySelector('.render-card');
        if (!firstCard) return;

        firstCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        firstCard.style.transition = 'box-shadow 0.3s ease';
        firstCard.style.boxShadow = '0 0 0 3px var(--bs-success, #198754)';

        setTimeout(() => {
            firstCard.style.boxShadow = '';
        }, 2500);

        const btn = firstCard.querySelector('.btn-clone-xtts-preview[data-job][data-url]');
        if (btn) {
            const jobId = btn.getAttribute('data-job');
            const url = btn.getAttribute('data-url');
            if (jobId && url && !S.previewWS.has(jobId)) {
                initPreview(jobId, url, true);
            }
        }
    }

    function spaSave(data) {
        try {
            localStorage.setItem(SPA_KEY, JSON.stringify({ ...data, ts: Date.now() }));
        } catch (_) {}
    }

    function spaLoad() {
        try {
            const raw = localStorage.getItem(SPA_KEY);
            if (!raw) return null;

            const data = JSON.parse(raw);
            if (Date.now() - (data.ts || 0) > 30 * 60 * 1000) {
                localStorage.removeItem(SPA_KEY);
                return null;
            }

            return data;
        } catch (_) {
            return null;
        }
    }

    function spaClear() {
        try { localStorage.removeItem(SPA_KEY); } catch (_) {}
    }

    function spaRestoreIfNeeded() {
        const saved = spaLoad();
        if (!saved || !saved.jobId) return;

        const activeStatuses = ['queued', 'running', 'saving'];
        if (!activeStatuses.includes(saved.status)) return;

        try {
            const lw = getLivewireComponent();
            if (!lw) return;
            if (typeof lw.set !== 'function') return;

            lw.set('currentJobId', saved.jobId);
            lw.set('providerJobId', saved.providerJobId || null);
            lw.set('currentStatus', saved.status);
            lw.set('jobFinished', false);
            lw.set('showJobStatus', true);
            lw.set('currentProgress', saved.progress || 10);
        } catch (e) {
            console.warn('[Clone XTTS] SPA restore failed', e);
        }
    }

    function formSave() {
        try {
            const lw = getLivewireComponent();
            if (!lw) return;

            const state = {
                text: lw.get('text') ?? '',
                language: lw.get('language') ?? 'ar',
                split: !!lw.get('split'),
                max_words: parseInt(safeNumber(lw.get('max_words'), 25), 10),
                fade_ms: parseInt(safeNumber(lw.get('fade_ms'), 80), 10),
                temperature: safeNumber(lw.get('temperature'), 0.65),
                top_k: parseInt(safeNumber(lw.get('top_k'), 50), 10),
                top_p: safeNumber(lw.get('top_p'), 0.80),
                repetition_penalty: safeNumber(lw.get('repetition_penalty'), 2.0),
                length_penalty: safeNumber(lw.get('length_penalty'), 1.0),
                speed: safeNumber(lw.get('speed'), 1.0),
                selectedPreset: lw.get('selectedPreset') ?? 'balanced',
                ts: Date.now(),
            };

            localStorage.setItem(FORM_KEY, JSON.stringify(state));
        } catch (_) {}
    }

    function formLoad() {
        try {
            const raw = localStorage.getItem(FORM_KEY);
            if (!raw) return null;

            const data = JSON.parse(raw);
            if (!data) return null;

            if (Date.now() - (data.ts || 0) > 7 * 24 * 60 * 60 * 1000) {
                localStorage.removeItem(FORM_KEY);
                return null;
            }

            return data;
        } catch (_) {
            return null;
        }
    }

    function formClear() {
        try { localStorage.removeItem(FORM_KEY); } catch (_) {}
    }

    function formRestoreIfNeeded() {
        const saved = formLoad();
        if (!saved) return;

        const lw = getLivewireComponent();
        if (!lw) return;

        try {
            if (typeof lw.get !== 'function' || typeof lw.set !== 'function') return;

            const currentText = String(lw.get('text') ?? '').trim();
            if (currentText.length > 0) return;

            lw.set('text', saved.text ?? '');
            lw.set('language', saved.language ?? 'ar');
            lw.set('split', !!saved.split);
            lw.set('max_words', parseInt(safeNumber(saved.max_words, 25), 10));
            lw.set('fade_ms', parseInt(safeNumber(saved.fade_ms, 80), 10));
            lw.set('selectedPreset', saved.selectedPreset ?? 'balanced');
            lw.set('temperature', safeNumber(saved.temperature, 0.65));
            lw.set('top_k', parseInt(safeNumber(saved.top_k, 50), 10));
            lw.set('top_p', safeNumber(saved.top_p, 0.80));
            lw.set('repetition_penalty', safeNumber(saved.repetition_penalty, 2.0));
            lw.set('length_penalty', safeNumber(saved.length_penalty, 1.0));
            lw.set('speed', safeNumber(saved.speed, 1.0));
        } catch (e) {
            console.warn('[Clone XTTS] Form restore failed', e);
        }
    }

    function watchAndPersistForm() {
        if (S.formWatchBoot) return;

        const lw = getLivewireComponent();
        if (!lw || typeof lw.$watch !== 'function') return;

        S.formWatchBoot = true;

        let timer = null;
        const debouncedSave = () => {
            clearTimeout(timer);
            timer = setTimeout(() => formSave(), 250);
        };

        [
            'text',
            'language',
            'split',
            'max_words',
            'fade_ms',
            'temperature',
            'top_k',
            'top_p',
            'repetition_penalty',
            'length_penalty',
            'speed',
            'selectedPreset',
        ].forEach((field) => {
            try {
                lw.$watch(field, debouncedSave);
            } catch (_) {}
        });
    }

    function registerLivewireEvents() {
        if (!window.Livewire || S.eventsBound) return;
        S.eventsBound = true;

        Livewire.on('clone-xtts-job-started', (data) => {
            spaSave(data);
            formSave();
        });

        Livewire.on('clone-xtts-job-state-sync', (data) => {
            const saved = spaLoad();
            if (saved && saved.jobId === data.jobId) {
                spaSave({ ...saved, ...data });
            }
        });

        Livewire.on('clone-xtts-job-state-clear', () => {
            spaClear();
        });

        Livewire.on('clone-xtts-form-state-clear', () => {
            formClear();
        });

        Livewire.on('clone-xtts-job-completed', () => {
            spaClear();
            requestAnimationFrame(() => {
                bindPreviewButtons();
                highlightLatestRender();
            });
        });

        if (!S.commitHooked && typeof Livewire.hook === 'function') {
            S.commitHooked = true;

            Livewire.hook('commit', ({ succeed }) => {
                succeed(() => {
                    requestAnimationFrame(() => {
                        bindPreviewButtons();
                        formSave();
                    });
                });
            });
        }
    }

    function bootCloneXttsPage() {
        const runBoot = () => {
            const root = document.getElementById('clone-xtts-page-root');
            if (!root) return;

            registerLivewireEvents();
            bindPreviewButtons();
            spaRestoreIfNeeded();
            formRestoreIfNeeded();
            watchAndPersistForm();

            const idle = 'requestIdleCallback' in window
                ? (cb) => requestIdleCallback(cb, { timeout: 2000 })
                : (cb) => setTimeout(cb, 500);

            idle(() => preloadAndRenderRecentAudio());
        };

        setTimeout(runBoot, 0);
    }

    function teardownCloneXttsPage() {
        formSave();

        S.previewWS.forEach((ws, jobId) => {
            if (ws.isPlaying && ws.isPlaying()) return;
            try { ws.destroy(); } catch (_) {}
            S.previewWS.delete(jobId);

            const meta = S.previewMeta.get(jobId);
            if (meta?.blobUrl) {
                try { URL.revokeObjectURL(meta.blobUrl); } catch (_) {}
            }
            S.previewMeta.delete(jobId);
        });

        S.formWatchBoot = false;
    }

    document.addEventListener('livewire:initialized', bootCloneXttsPage);
    document.addEventListener('livewire:navigated', bootCloneXttsPage);
    document.addEventListener('livewire:navigating', teardownCloneXttsPage);

    window.addEventListener('beforeunload', () => {
        formSave();

        S.previewWS.forEach(ws => {
            try { ws.destroy(); } catch (_) {}
        });
        S.previewWS.clear();
    });
})();
</script>

<script>
window.addEventListener('clone-xtts-form-state-clear', () => {
    try {
        localStorage.removeItem('clone_xtts_form_state_v1');
    } catch (_) {}
});
</script>
@endpush
