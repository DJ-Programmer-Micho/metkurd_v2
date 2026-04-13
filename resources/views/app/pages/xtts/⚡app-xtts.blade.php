<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Livewire\Component;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use App\Models\Tool;
use App\Models\ToolAction;
use App\Models\MlJob;
use App\Models\Voice;
use App\Support\AppToolCatalog;

use App\Services\Providers\RunPodProvider;
use App\Services\Billing\CreditService;

use App\Services\XTTS\XttsJobSyncService;
use App\Services\Security\JobExecutionLockService;
new
#[Layout('app::layouts.app')]
class extends Component
{
    protected string $toolCode = 'tts';
    protected string $actionCode = 'standard';
    protected string $fullActionCode = 'tts.standard';
    protected string $voiceEngine = 'xtts';

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
    public ?string $text = '';

    public string $speaker_id = 'liza';
    public string $language = 'ar';
    public array $availableSpeakers = [];
    public array $speakerPickerPayload = [];

    public bool $split = true;
    public int $max_words = 25;
    public int $fade_ms = 80;

    public float $temperature = 0.65;
    public int $top_k = 50;
    public float $top_p = 0.8;
    public float $repetition_penalty = 2.0;
    public float $length_penalty = 1.0;
    public float $speed = 1.0;

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
    public int $completedNoAudioTicks = 0;

    #[On('header:refresh')]
    #[On('customerStorageUpdated')]
    #[On('xtts-renders-refresh')]
    public function refreshUi(): void
    {
        $this->syncWallet();
        $this->syncCostPreview();
        $this->hydrateCurrentJobFromDb();
    }

    #[On('customerPlanUpdated')]
    public function refreshPlanUi(): void
    {
        $this->hydrateSpeakerCatalog();

        if (!array_key_exists($this->speaker_id, $this->availableSpeakers)) {
            $this->speaker_id = array_key_first($this->availableSpeakers) ?? '';
        }

        $this->syncSpeakerPickerSelection();
        $this->refreshUi();
    }

    public function mount(): void
    {
        $this->syncWallet();
        $this->hydrateSpeakerCatalog();

        if (!array_key_exists($this->speaker_id, $this->availableSpeakers)) {
            $this->speaker_id = array_key_first($this->availableSpeakers) ?? '';
        }

        $this->syncSpeakerPickerSelection();
        $this->applyPreset($this->selectedPreset);
        $this->syncCostPreview();
        $this->dismissedJobStatusFor = session('xtts.dismissed_job_status_for');
        $this->hydrateCurrentJobFromDb();
    }

    public function updatedText(): void
    {
        $this->syncCostPreview();
    }

    public function updatedSpeakerId(): void
    {
        $this->syncCostPreview();
        $this->syncSpeakerPickerSelection();
    }

    public function updatedLanguage(): void
    {
        $this->syncCostPreview();
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

    protected function hydrateSpeakerCatalog(): void
    {
        $this->availableSpeakers = $this->resolveAvailableSpeakers();
        $this->speakerPickerPayload = $this->buildSpeakerPickerData();
    }

    protected function resolveAvailableSpeakers(): array
    {
        $customer = auth('app')->user();
        if (!$customer) {
            return [];
        }

        $planId = method_exists($customer, 'currentServicePlanId')
            ? (int) ($customer->currentServicePlanId() ?? 0)
            : 0;

        if ($planId <= 0) {
            $planId = (int) ($customer->service_plan_id ?? 0);
        }

        if (!$planId) {
            return [];
        }

        return app(AppToolCatalog::class)->voiceOptionsForPlanAndEngine((int) $planId, $this->voiceEngine);
    }

    protected function buildSpeakerPickerData(): array
    {
        return [
            'selected' => (string) $this->speaker_id,
            'groups' => $this->buildSpeakerPickerGroups(),
            'group_order' => [
                ['key' => 'female', 'label' => __('Female')],
                ['key' => 'male', 'label' => __('Male')],
            ],
            'messages' => [
                'noSpeakerSelected' => __('No speaker selected'),
                'previewUnavailable' => __('Preview unavailable.'),
                'previewPlay' => __('Play preview for :speaker'),
                'previewPause' => __('Pause preview for :speaker'),
                'previewResume' => __('Resume preview for :speaker'),
                'previewRetry' => __('Retry preview for :speaker'),
                'previewPlayShort' => __('Play preview'),
                'previewPauseShort' => __('Pause preview'),
                'previewResumeShort' => __('Resume preview'),
                'previewRetryShort' => __('Retry preview'),
                'previewLoadingShort' => __('Loading...'),
                'previewLoadingHint' => __('Loading preview...'),
                'previewReadyHint' => __('Tap to preview.'),
                'previewPausedHint' => __('Preview paused.'),
                'previewPlayingHint' => __('Preview playing.'),
                'previewRetryHint' => __('Preview could not be loaded.'),
                'previewUnavailableShort' => __('No preview'),
            ],
        ];
    }

    protected function buildSpeakerPickerGroups(): array
    {
        $available = $this->availableSpeakers;

        if ($available === []) {
            return [
                'female' => [],
                'male' => [],
            ];
        }

        $codes = array_values(array_map('strval', array_keys($available)));
        $cacheKeyCodes = $codes;
        sort($cacheKeyCodes);

        /** @var array<string, array{name: string, meta: array}> $voiceMeta */
        $voiceMeta = cache()->remember(
            'xtts-speaker-picker:' . md5(implode('|', $cacheKeyCodes)),
            now()->addMinutes(15),
            function () use ($codes): array {
                return Voice::query()
                    ->whereIn('code', $codes)
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get(['code', 'name', 'meta'])
                    ->mapWithKeys(fn (Voice $voice) => [
                        (string) $voice->code => [
                            'name' => (string) $voice->name,
                            'meta' => (array) ($voice->meta ?? []),
                        ],
                    ])
                    ->all();
            }
        );

        $groups = [
            'female' => [],
            'male' => [],
        ];

        $locale = $this->speakerMediaLocale();

        foreach ($available as $code => $label) {
            $code = (string) $code;
            $voice = (array) ($voiceMeta[$code] ?? []);
            $voiceName = trim((string) ($voice['name'] ?? $label ?? $code));
            $meta = (array) ($voice['meta'] ?? []);
            $displayName = $this->speakerDisplayName($code, $voiceName);
            $group = $this->speakerGroupKey($code, $voiceName, $meta, $groups);

            $groups[$group][] = [
                'code' => $code,
                'display_name' => $displayName,
                'subtitle' => $this->speakerSubtitle($displayName, $meta),
                'gender' => $group,
                'gender_label' => $group === 'male' ? __('Male') : __('Female'),
                'avatar_url' => $this->speakerAvatarRoute($code, $locale),
                'avatar_initials' => $this->speakerAvatarInitials($displayName),
                'has_preview' => true,
                'preview_url' => $this->speakerPreviewRoute($code, $locale),
            ];
        }
        return $groups;
    }

    protected function syncSpeakerPickerSelection(): void
    {
        if ($this->speakerPickerPayload === []) {
            return;
        }

        $this->speakerPickerPayload['selected'] = (string) $this->speaker_id;
    }

    protected function speakerGroupKey(string $code, string $voiceName, array $meta, array $currentGroups = []): string
    {
        $gender = Str::of((string) data_get($meta, 'gender'))
            ->lower()
            ->trim()
            ->value();

        if (in_array($gender, ['female', 'male'], true)) {
            return $gender;
        }

        $haystack = Str::of($code . ' ' . $voiceName)
            ->lower()
            ->replace(['-', '_'], ' ')
            ->value();

        if (str_contains($haystack, 'female') || str_contains($haystack, 'woman')) {
            return 'female';
        }

        if (str_contains($haystack, 'male') || str_contains($haystack, 'man')) {
            return 'male';
        }

        return count($currentGroups['female'] ?? []) <= count($currentGroups['male'] ?? []) ? 'female' : 'male';
    }

    protected function speakerDisplayName(string $code, string $voiceName): string
    {
        $raw = trim($voiceName);

        if ($raw === '' || strcasecmp($raw, $code) === 0) {
            return __('Voice');
        }

        $clean = Str::of($voiceName)
            ->replaceMatches('/[^\p{L}\p{N}\s\-_]+/u', ' ')
            ->squish()
            ->value();

        return $clean !== '' ? $clean : __('Voice');
    }

    protected function speakerSubtitle(string $displayName, array $meta): ?string
    {
        foreach (['subtitle', 'style', 'accent', 'tone', 'description'] as $key) {
            $value = trim((string) data_get($meta, $key, ''));

            if ($value !== '') {
                return $value;
            }
        }

        $voiceStyle = trim((string) data_get($meta, 'engine', ''));

        if ($voiceStyle !== '' && strcasecmp($voiceStyle, $this->voiceEngine) !== 0) {
            return Str::headline($voiceStyle);
        }

        return null;
    }

    protected function speakerMediaLocale(): string
    {
        return (string) (request()->route('locale') ?: app()->getLocale());
    }

    protected function speakerPreviewRoute(string $code, ?string $locale = null): string
    {
        return route('app.xtts.speaker.preview', [
            'locale' => $locale ?: $this->speakerMediaLocale(),
            'voiceCode' => $code,
            'proxy' => 1,
        ]);
    }

    protected function speakerAvatarRoute(string $code, ?string $locale = null): string
    {
        return route('app.xtts.speaker.avatar', [
            'locale' => $locale ?: $this->speakerMediaLocale(),
            'voiceCode' => $code,
        ]);
    }

    protected function speakerAvatarInitials(string $displayName): string
    {
        $displayName = trim($displayName);

        if ($displayName === '') {
            return 'V';
        }

        $parts = collect(preg_split('/\s+/u', $displayName, -1, PREG_SPLIT_NO_EMPTY))
            ->filter()
            ->values();

        if ($parts->count() >= 2) {
            return mb_strtoupper(
                mb_substr((string) $parts[0], 0, 1) . mb_substr((string) $parts[1], 0, 1)
            );
        }

        return mb_strtoupper(mb_substr((string) $parts->first(), 0, 2) ?: 'V');
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

        if (empty($this->availableSpeakers)) {
            return __('No voices are available for your current plan.');
        }

        if (!array_key_exists($this->speaker_id, $this->availableSpeakers)) {
            return __('Selected voice is not available for your current plan.');
        }

        if ($this->creditsCost <= 0) {
            return __('Pricing could not be calculated.');
        }

        if ($this->walletBalance < $this->creditsCost) {
            return __('Not enough credits.');
        }

        return null;
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
                'speaker_id' => $this->speaker_id,
            ]);
            return;
        }

        $this->creditsCost = (int) ceil($chars * 1.0);
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

        $toolId = app(AppToolCatalog::class)->toolId($this->toolCode);

        if (!$toolId) {
            return 0;
        }

        return MlJob::query()
            ->where('customer_id', $customerId)
            ->where('tool_id', $toolId)
            ->whereIn('status', ['queued', 'running', 'saving'])
            ->count();
    }

    protected function rules(): array
    {
        return [
            'text' => ['required', 'string', 'min:1', 'max:' . $this->maxPerSubmit],
            'speaker_id' => ['required', 'string', function ($attribute, $value, $fail) {
                if (!array_key_exists((string) $value, $this->availableSpeakers)) {
                    $fail(__('The selected speaker is not available for your plan.'));
                }
            }],
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
            session(['xtts.dismissed_job_status_for' => $this->currentJobId]);
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
            throw new \RuntimeException(__('Tool or ToolAction is missing for :tool.', ['tool' => "{$this->toolCode} / {$this->fullActionCode}"]));
        }

        return [$tool, $action];
    }

    public function postXtts(RunPodProvider $runpod, CreditService $credits): void
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
            $this->dispatch('alert', type: 'error', message: __('Your plan does not allow XTTS.'));
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
                'speaker_id' => $this->speaker_id,
            ])
            : (int) ceil($chars * 1.0);

        if ($cost <= 0) {
            $this->dispatch('alert', type: 'error', message: __('Pricing is not configured.'));
            return;
        }

        try {
            $credits->charge((int) $c->id, $cost, 'tts_charge', [
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
        session()->forget('xtts.dismissed_job_status_for');
        MlJob::create([
            'id'             => $jobId,
            'customer_id'    => $c->id,
            'tool_id'        => $tool->id,
            'tool_action_id' => $action->id,
            'job_kind'       => 'tts',
            'status'         => 'queued',
            'provider'       => 'runpod',
            'input' => [
                'text' => $text,
                'speaker_id' => $this->speaker_id,
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
            ],
            'credits_charged' => $cost,
            'started_at' => now(),
        ]);

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
                throw new \RuntimeException(__('XTTS endpoint ID is missing.'));
            }

            $timeout = (int) (data_get($tool->meta, 'runpod_timeout') ?: config('runpod.timeout', 60));

            $resp = $runpod->run($endpointId, [
                'text' => $text,
                'language' => $this->language,
                'speaker' => $this->speaker_id,
                'enable_text_splitting' => (bool) $this->split,
                'max_words' => (int) $this->max_words,
                'temperature' => (float) $this->temperature,
                'length_penalty' => (float) $this->length_penalty,
                'repetition_penalty' => (float) $this->repetition_penalty,
                'top_k' => (int) $this->top_k,
                'top_p' => (float) $this->top_p,
                'speed' => (float) $this->speed,
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
            $this->dispatch('xtts-renders-refresh');

            // Persist SPA job state to JS
            $this->dispatch('xtts-job-started', [
                'jobId'       => $jobId,
                'providerJobId' => $rpId,
                'status'      => 'running',
                'progress'    => 20,
            ]);

            $this->dispatch('alert', type: 'success', message: __('RunPod job started.'));
        } catch (\Throwable $e) {
            $this->dispatch('header:refresh');
            $credits->refund((int) $c->id, $cost, 'tts_refund', [
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
            $this->currentStatus = 'failed';
            $this->jobFinished = true;
            $this->currentProgress = 100;
            $this->syncWallet();
            
            $this->dispatch('header:refresh');
            $this->dispatch('xtts-job-state-clear');
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

            $this->dispatch('xtts-job-state-sync', [
                'jobId' => $this->currentJobId,
                'status' => $this->currentStatus,
                'progress' => $this->currentProgress,
            ]);

            if (!empty($result['done'])) {
                $this->syncWallet();
                $this->dispatch('customerPlanUpdated');
                $this->dispatch('customerStorageUpdated');
                $this->dispatch('xtts-renders-refresh');
                $this->dispatch('xtts-job-completed');
                $this->dispatch('xtts-job-state-clear');
                $this->dispatch('alert', type: 'success', message: __('Done'));
            }

            if (!empty($result['failed'])) {
                $this->dispatch('header:refresh');
                $this->dispatch('xtts-job-state-clear');
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
            $this->dispatch('xtts-job-state-clear');
            $this->dispatch('alert', type: 'error', message: __('Polling failed: :message', ['message' => $e->getMessage()]));
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
        $this->speaker_id = array_key_first($this->availableSpeakers) ?? 'liza';
        $this->language = 'ar';
        $this->split = true;
        $this->max_words = 25;
        $this->fade_ms = 80;

        $this->selectedPreset = 'balanced';
        $this->applyPreset($this->selectedPreset);

        $this->syncSpeakerPickerSelection();
        $this->syncCostPreview();

        $this->dispatch('xtts-form-state-clear');
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
            $this->dispatch('xtts-job-state-clear');
            $this->dispatch('xtts-form-state-clear');
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
                Log::warning('TTS_ELIMINATE_REF_DELETE_FAIL', [
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
        $this->dispatch('xtts-job-state-clear');
        $this->dispatch('xtts-form-state-clear');
        $this->dispatch('xtts-renders-refresh');
        $this->dispatch('alert', type: 'warning', message: __('Current job eliminated. Credits were not refunded.'));
    }
    
    public function render()
    {
        return view('app.pages.xtts.⚡app-xtts');
    }

    protected function hydrateCurrentJobFromDb(): void
    {
        $customerId = auth('app')->id();
        $toolId = app(AppToolCatalog::class)->toolId($this->toolCode);

        if (!$customerId || !$toolId) {
            return;
        }

        $job = MlJob::query()
            ->where('customer_id', $customerId)
            ->where('tool_id', $toolId)
            ->where(function ($q) {
                $q->whereIn('status', ['queued', 'running', 'saving'])
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

    <x-slot:title>{{ __('XTTS') }} | {{ __('MET KURD') }}</x-slot:title>

    <div id="xtts-page-root">
    {{-- Poll only when a job is actively running --}}
    @if($currentJobId && !$jobFinished)
        <div wire:poll.visible.6000ms="pollJob"></div>
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
            {{-- @php
                dd($showJobStatus, $currentJobId, $currentStatus);
            @endphp --}}
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
                                <strong>{{ __('MK-TTS (MET KURDISH TEXT-TO-SPEECH)') }}</strong>
                                <div class="text-muted small">{{ __('Dynamic voice access based on customer plan') }}</div>
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

                            @php($speakerPicker = $speakerPickerPayload)

                            <div class="row g-3 align-items-start">
                                <div class="col-12">
                                    <div
                                        class="xtts-speaker-picker"
                                        x-data="xttsSpeakerPicker(@js($speakerPicker))"
                                        x-init="init()"
                                        x-on:xtts-speaker-preview-state.window="syncPreviewState($event.detail)"
                                    >
                                        <select
                                            class="d-none"
                                            x-ref="speakerSelect"
                                            wire:model.change="speaker_id"
                                            x-on:change="syncSelectedFromNative($event.target.value)"
                                            aria-hidden="true"
                                            tabindex="-1"
                                        >
                                            @foreach($this->availableSpeakers as $speakerCode => $speakerLabel)
                                                <option value="{{ $speakerCode }}">{{ $speakerLabel }}</option>
                                            @endforeach
                                        </select>

                                        <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
                                            <div>
                                                <label class="form-label mb-1">{{ __('Speaker') }}</label>
                                                <div class="text-muted small">{{ __('Choose a voice and preview it before generating.') }}</div>
                                            </div>

                                            <div class="small text-muted">
                                                <span class="xtts-speaker-selection-pill" x-text="selectedLabel() || messages.noSpeakerSelected"></span>
                                            </div>
                                        </div>
                                        <div class="row g-3" role="radiogroup" aria-label="{{ __('Speaker voice picker') }}">
                                            <template x-for="group in groupOrder" :key="group.key">
                                                <div class="col-12 col-xl-6">
                                                    <div class="xtts-speaker-group h-100">
                                                        <div class="xtts-speaker-group-head d-flex align-items-center justify-content-between gap-2">
                                                            <div class="fw-semibold" x-text="group.label"></div>
                                                            <span class="xtts-speaker-count" x-text="speakerCount(group.key)"></span>
                                                        </div>

                                                        <div class="xtts-speaker-group-body">
                                                            <template x-if="speakerCount(group.key)">
                                                                <div class="d-flex flex-column gap-3">
                                                                    <template x-for="speaker in groupSpeakers(group.key)" :key="speaker.code">
                                                                        <div
                                                                            class="xtts-speaker-card"
                                                                            :class="{ 'is-selected': isSelected(speaker.code) }"
                                                                            role="radio"
                                                                            tabindex="0"
                                                                            :aria-checked="isSelected(speaker.code) ? 'true' : 'false'"
                                                                            x-on:click="selectSpeaker(speaker.code)"
                                                                            x-on:keydown.enter.prevent="selectSpeaker(speaker.code)"
                                                                            x-on:keydown.space.prevent="selectSpeaker(speaker.code)"
                                                                        >
                                                                            <div class="d-flex align-items-start gap-2">
                                                                                <div class="xtts-speaker-avatar">
                                                                                    <img
                                                                                        x-cloak
                                                                                        x-show="showAvatar(speaker)"
                                                                                        :src="speaker.avatar_url || ''"
                                                                                        :alt="speaker.display_name"
                                                                                        class="xtts-speaker-avatar-image"
                                                                                        loading="lazy"
                                                                                        x-on:error="markAvatarError(speaker.code, $event.target)"
                                                                                    >

                                                                                    <span
                                                                                        x-cloak
                                                                                        x-show="!showAvatar(speaker)"
                                                                                        class="xtts-speaker-avatar-fallback"
                                                                                        x-text="speaker.avatar_initials"
                                                                                    ></span>
                                                                                </div>

                                                                                <div class="flex-grow-1 min-w-0">
                                                                                    <div class="d-flex align-items-start justify-content-between gap-2">
                                                                                        <div class="min-w-0">
                                                                                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                                                                                <div class="fw-semibold  text-break" x-text="speaker.display_name"></div>
                                                                                                <span class="xtts-speaker-badge" x-text="speaker.gender_label"></span>
                                                                                            </div>

                                                                                            <template x-if="speaker.subtitle">
                                                                                                <div class="text-muted small mt-1 xtts-speaker-subtitle" x-text="speaker.subtitle"></div>
                                                                                            </template>
                                                                                        </div>

                                                                                        <div class="xtts-speaker-check" x-show="isSelected(speaker.code)" aria-hidden="true">
                                                                                            <i class="fa fa-check-circle"></i>
                                                                                        </div>
                                                                                    </div>

                                                                                    <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mt-2">
                                                                                        <div class="d-flex align-items-center gap-2 flex-wrap min-w-0">
                                                                                            <template x-if="isSelected(speaker.code)">
                                                                                                <span class="xtts-speaker-state xtts-speaker-state--selected">{{ __('Selected') }}</span>
                                                                                            </template>

                                                                                            <template x-if="isPlaying(speaker.code)">
                                                                                                <span class="xtts-speaker-state xtts-speaker-state--live">{{ __('Previewing') }}</span>
                                                                                            </template>

                                                                                            <template x-if="isPaused(speaker.code)">
                                                                                                <span class="xtts-speaker-state">{{ __('Paused') }}</span>
                                                                                            </template>

                                                                                            <span class="xtts-speaker-preview-meta" aria-live="polite" x-text="previewStatusText(speaker.code)"></span>
                                                                                        </div>

                                                                                        <button
                                                                                            type="button"
                                                                                            class="xtts-speaker-preview-button xtts-speaker-preview-button--compact"
                                                                                            :class="{ 'is-loading': isLoading(speaker.code), 'is-playing': isPlaying(speaker.code), 'is-paused': isPaused(speaker.code), 'is-error': Boolean(previewError(speaker.code)) }"
                                                                                            x-on:click.stop="togglePreview(speaker.code)"
                                                                                            :aria-label="previewLabel(speaker.code)"
                                                                                            :title="previewLabel(speaker.code)"
                                                                                            :aria-pressed="isPlaying(speaker.code) ? 'true' : 'false'"
                                                                                            :aria-busy="isLoading(speaker.code) ? 'true' : 'false'"
                                                                                            :disabled="!hasPreview(speaker.code)"
                                                                                        >
                                                                                            <span class="xtts-speaker-preview-icon" aria-hidden="true">
                                                                                                <i class="fa" :class="previewButtonIcon(speaker.code)"></i>
                                                                                            </span>

                                                                                            <span class="xtts-speaker-preview-text" x-text="previewButtonText(speaker.code)"></span>
                                                                                        </button>
                                                                                    </div>

                                                                                    <template x-if="previewError(speaker.code)">
                                                                                        <div class="small text-warning mt-1" x-text="previewError(speaker.code)"></div>
                                                                                    </template>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    </template>
                                                                </div>
                                                            </template>

                                                            <template x-if="!speakerCount(group.key)">
                                                                <div class="xtts-speaker-empty">
                                                                    {{ __('No voices available in this group yet.') }}
                                                                </div>
                                                            </template>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>

                                        <div class="text-muted small mt-3">
                                            {{ __('Only one speaker preview plays at a time.') }}
                                        </div>
                                    </div>

                                    @error('speaker_id')
                                        <div class="text-danger small mt-2">{{ $message }}</div>
                                    @enderror
                                </div>

                                {{-- <div class="col-md-4">
                                    <label class="form-label">Language</label>
                                    <input type="text"
                                           class="form-control"
                                           wire:model.change="language"
                                           maxlength="8"
                                           placeholder="ar">
                                    @error('language')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div> --}}

                                <div class="col-md-4">
                                    <label class="form-label">{{ __('Preset') }}</label>
                                    <select class="form-select" wire:model.change="selectedPreset">
                                        @foreach($presets as $k => $v)
                                            <option value="{{ $k }}">{{ __($v) }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">{{ __('Max Words') }}</label>
                                    <input type="number" class="form-control" wire:model.change="max_words" min="5" max="80">
                                    @error('max_words')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">{{ __('Fade (ms)') }}</label>
                                    <input type="number" class="form-control" wire:model.change="fade_ms" min="0" max="1000">
                                    @error('fade_ms')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-12">
                                    <div class="form-check form-switch mt-2">
                                        <input class="form-check-input" type="checkbox" id="splitSwitchXTTS" wire:model.change="split">
                                        <label class="form-check-label" for="splitSwitchXTTS">{{ __('Split long text automatically') }}</label>
                                    </div>
                                </div>
                            </div>

                            {{-- =====================================================
                                 SLIDERS Ã¢â‚¬â€ Alpine handles the UI, $wire.set syncs to Livewire
                                 wire:ignore prevents Livewire re-renders from resetting slider position
                                 ===================================================== --}}
                            <div class="row g-3 mt-1">
                                @foreach($this->sliders as $s)
                                    <div
                                        class="col-md-6"
                                        wire:key="slider-{{ $s['key'] }}-{{ md5((string) $s['val']) }}"
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
                                    wire:click="postXtts"
                                    wire:loading.attr="disabled"
                                    wire:target="postXtts"
                                    @disabled(!$this->canGenerate)
                                    type="button"
                                    id="btn-xtts-generate"
                                >
                                    <span wire:loading.remove wire:target="postXtts">
                                        {{ $this->canGenerate ? __('Generate') : ($this->generateBlockedReason ?? __('Generate')) }}
                                    </span>
                                    <span wire:loading wire:target="postXtts">
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

        <livewire:partials.xtts-renders-panel />
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
<style>
    .xtts-speaker-picker [x-cloak] {
        display: none !important;
    }

    .xtts-speaker-picker {
        padding: 1rem;
        border: 1px solid rgba(148, 163, 184, 0.16);
        border-radius: 1.1rem;
        background: linear-gradient(180deg, rgba(15, 23, 42, 0.72), rgba(15, 23, 42, 0.5));
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
        backdrop-filter: blur(8px);
    }

    .xtts-speaker-selection-pill {
        display: inline-flex;
        align-items: center;
        padding: 0.45rem 0.8rem;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, 0.18);
        background: rgba(2, 6, 23, 0.62);
        color: rgba(226, 232, 240, 0.96);
        font-weight: 500;
    }

    .xtts-speaker-group {
        border: 1px solid rgba(148, 163, 184, 0.12);
        border-radius: 1rem;
        background: rgba(2, 6, 23, 0.38);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
        overflow: hidden;
    }

    .xtts-speaker-group-head {
        padding: 0.9rem 1rem;
        border-bottom: 1px solid rgba(148, 163, 184, 0.12);
        background: rgba(255, 255, 255, 0.03);
    }

    .xtts-speaker-count {
        display: inline-flex;
        min-width: 2rem;
        justify-content: center;
        padding: 0.2rem 0.55rem;
        border-radius: 999px;
        border: 1px solid rgba(59, 130, 246, 0.2);
        background: rgba(59, 130, 246, 0.14);
        color: rgba(191, 219, 254, 0.96);
        font-size: 0.78rem;
        font-weight: 600;
    }

    .xtts-speaker-group-body {
        max-height: 22rem;
        overflow-y: auto;
        padding: 0.75rem;
        display: flex;
        flex-direction: column;
        gap: 0.65rem;
    }

    .xtts-speaker-card {
        position: relative;
        padding: 0.78rem 0.82rem;
        border: 1px solid rgba(148, 163, 184, 0.15);
        border-radius: 0.9rem;
        background: rgba(15, 23, 42, 0.76);
        cursor: pointer;
        transition: border-color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
    }

    .xtts-speaker-card:hover {
        transform: translateY(-1px);
        border-color: rgba(96, 165, 250, 0.42);
        background: rgba(17, 24, 39, 0.92);
    }

    .xtts-speaker-card:focus-visible {
        outline: none;
        border-color: rgba(96, 165, 250, 0.58);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.16);
    }

    .xtts-speaker-card.is-selected {
        border-color: rgba(96, 165, 250, 0.82);
        background: linear-gradient(180deg, rgba(30, 41, 59, 0.94), rgba(15, 23, 42, 0.92));
        box-shadow: 0 0 0 1px rgba(96, 165, 250, 0.18), 0 10px 22px rgba(2, 6, 23, 0.24);
    }

    .xtts-speaker-avatar {
        width: 2.65rem;
        height: 2.65rem;
        flex: 0 0 2.65rem;
        border-radius: 1.85rem;
        border: 1px solid rgba(148, 163, 184, 0.16);
        background: linear-gradient(180deg, rgba(51, 65, 85, 0.88), rgba(15, 23, 42, 0.96));
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.05);
        overflow: hidden;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .xtts-speaker-avatar-image {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .xtts-speaker-avatar-fallback {
        width: 100%;
        height: 100%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: rgba(226, 232, 240, 0.92);
        font-size: 0.82rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
    }

    .xtts-speaker-badge,
    .xtts-speaker-state {
        display: inline-flex;
        align-items: center;
        padding: 0.2rem 0.5rem;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, 0.18);
        background: rgba(255, 255, 255, 0.04);
        color: rgba(226, 232, 240, 0.9);
        font-size: 0.7rem;
        line-height: 1.1;
    }

    .xtts-speaker-subtitle {
        line-height: 1.25;
    }

    .xtts-speaker-badge {
        background: rgba(59, 130, 246, 0.12);
        color: rgba(191, 219, 254, 0.96);
    }

    .xtts-speaker-state--selected {
        border-color: rgba(59, 130, 246, 0.24);
        background: rgba(59, 130, 246, 0.15);
        color: rgba(191, 219, 254, 0.98);
    }

    .xtts-speaker-state--live {
        border-color: rgba(34, 197, 94, 0.22);
        background: rgba(34, 197, 94, 0.14);
        color: rgba(187, 247, 208, 0.98);
    }

    .xtts-speaker-preview-meta {
        color: rgba(203, 213, 225, 0.76);
        font-size: 0.76rem;
        line-height: 1.2;
    }

    .xtts-speaker-preview-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        min-height: 2.35rem;
        padding: 0.52rem 0.8rem;
        border: 1px solid rgba(148, 163, 184, 0.18);
        border-radius: 0.8rem;
        background: rgba(2, 6, 23, 0.62);
        color: #f8fafc;
        font-weight: 600;
        font-size: 0.82rem;
        transition: border-color 0.18s ease, background 0.18s ease, color 0.18s ease, transform 0.18s ease;
    }

    .xtts-speaker-preview-button--compact {
        width: auto;
        flex: 0 0 auto;
        white-space: nowrap;
    }

    .xtts-speaker-preview-button:hover:not(:disabled) {
        transform: translateY(-1px);
        border-color: rgba(96, 165, 250, 0.44);
        color: #ffffff;
    }

    .xtts-speaker-preview-button:focus-visible {
        outline: none;
        border-color: rgba(96, 165, 250, 0.58);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.14);
    }

    .xtts-speaker-preview-button.is-playing {
        background: var(--bs-primary, #0d6efd);
        border-color: var(--bs-primary, #0d6efd);
        color: #ffffff;
    }

    .xtts-speaker-preview-button.is-loading {
        border-color: rgba(148, 163, 184, 0.3);
        background: rgba(30, 41, 59, 0.88);
    }

    .xtts-speaker-preview-button.is-paused {
        border-color: rgba(96, 165, 250, 0.36);
        background: rgba(30, 41, 59, 0.7);
    }

    .xtts-speaker-preview-button.is-error:not(:disabled) {
        border-color: rgba(245, 158, 11, 0.3);
        color: rgba(253, 224, 71, 0.96);
    }

    .xtts-speaker-preview-button:disabled {
        opacity: 0.52;
        cursor: not-allowed;
    }

    .xtts-speaker-preview-icon {
        width: 1rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.85rem;
        flex: 0 0 1rem;
    }

    .xtts-speaker-preview-text {
        display: inline-block;
        line-height: 1.15;
    }

    .xtts-speaker-check {
        color: rgba(96, 165, 250, 0.96);
        font-size: 1.05rem;
        line-height: 1;
        flex: 0 0 auto;
    }

    .xtts-speaker-empty {
        padding: 0.9rem;
        border: 1px dashed rgba(148, 163, 184, 0.18);
        border-radius: 0.9rem;
        color: rgba(148, 163, 184, 0.92);
        background: rgba(15, 23, 42, 0.42);
        font-size: 0.92rem;
        text-align: center;
    }

    @media (max-width: 767.98px) {
        .xtts-speaker-picker {
            padding: 0.85rem;
        }

        .xtts-speaker-group-body {
            max-height: 18rem;
        }

        .xtts-speaker-card {
            padding: 0.74rem 0.78rem;
        }

        .xtts-speaker-avatar {
            width: 2.45rem;
            height: 2.45rem;
            flex-basis: 2.45rem;
        }

        .xtts-speaker-preview-button--compact {
            width: 100%;
        }
    }
</style>
@endpush

@push('scripts')
<script src="https://unpkg.com/wavesurfer.js@7/dist/wavesurfer.min.js"></script>
<script>
(function () {
    'use strict';

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Singleton namespace Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    if (!window.__XTTS_WAVE__) window.__XTTS_WAVE__ = {};
    const S = window.__XTTS_WAVE__;

    S.previewWS     = S.previewWS     || new Map(); // jobId Ã¢â€ â€™ WaveSurfer
    S.previewMeta   = S.previewMeta   || new Map(); // jobId Ã¢â€ â€™ { url, blobUrl }
    S.previewInit   = S.previewInit   || new Set(); // jobIds already preloaded
    S.pendingFetch  = S.pendingFetch  || new Map(); // url Ã¢â€ â€™ Promise<Blob>
    S.eventsBound   = S.eventsBound   || false;
    S.commitHooked  = S.commitHooked  || false;
    S.formWatchBoot = S.formWatchBoot || false;
    S.speakerPlayer = S.speakerPlayer || { audio: null, code: null, status: 'idle', urls: [], index: 0 };
    S.speakerErrors = S.speakerErrors || new Map();
    S.speakerPreviewToken = S.speakerPreviewToken || 0;

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Cache config Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    const CACHE_NAME    = 'xtts-audio-v4';
    const CACHE_MAX     = 30;
    const PRELOAD_LIMIT = 10;

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ SPA / navigation persistence via localStorage Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    const SPA_KEY  = 'xtts_spa_job';
    const FORM_KEY = 'xtts_form_state_v1';

    function safeNumber(value, fallback) {
        const n = Number(value);
        return Number.isFinite(n) ? n : fallback;
    }
    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Helpers Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
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

        const root = document.getElementById('xtts-page-root');
        if (!root) return null;

        const wireId = root.getAttribute('wire:id');
        if (!wireId) return null;

        try {
            return window.Livewire.find(wireId);
        } catch (_) {
            return null;
        }
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Audio Cache Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    function setSpeakerPlayerState(state = {}) {
        S.speakerPlayer = {
            audio: null,
            code: null,
            status: 'idle',
            urls: [],
            index: 0,
            ...(S.speakerPlayer || {}),
            ...state,
        };

        return S.speakerPlayer;
    }

    function speakerPreviewSnapshot() {
        const player = S.speakerPlayer || {};

        return {
            code: player.status === 'playing' ? (player.code || null) : null,
            pausedCode: player.status === 'paused' ? (player.code || null) : null,
            loadingCode: player.status === 'loading' ? (player.code || null) : null,
            errors: Object.fromEntries(S.speakerErrors.entries()),
        };
    }

    function dispatchSpeakerPreviewState(extra = {}) {
        window.dispatchEvent(new CustomEvent('xtts-speaker-preview-state', {
            detail: {
                ...speakerPreviewSnapshot(),
                ...extra,
            },
        }));
    }

    function detachSpeakerAudioEvents(audio) {
        if (!audio) {
            return;
        }

        audio.onended = null;
        audio.onerror = null;
        audio.onpause = null;
        audio.onplaying = null;
    }

    function stopSpeakerPreview({ resetTime = true, notify = true } = {}) {
        S.speakerPreviewToken += 1;

        const player = S.speakerPlayer;
        const audio = player?.audio || null;

        if (audio) {
            detachSpeakerAudioEvents(audio);

            try {
                audio.pause();

                if (resetTime) {
                    audio.currentTime = 0;
                }
            } catch (_) {}
        }

        setSpeakerPlayerState({
            audio: null,
            code: null,
            status: 'idle',
            urls: [],
            index: 0,
        });

        if (notify) {
            dispatchSpeakerPreviewState();
        }
    }

    function finalizeSpeakerPreviewError(voiceCode, message) {
        if (voiceCode) {
            S.speakerErrors.set(voiceCode, message);
        }

        setSpeakerPlayerState({
            audio: null,
            code: null,
            status: 'idle',
            urls: [],
            index: 0,
        });

        dispatchSpeakerPreviewState();
    }

    function playSpeakerAudio(audio, voiceCode, fallback, { token = null, pausedStateOnError = false } = {}) {
        if (!audio || !voiceCode) {
            return;
        }

        const requestToken = token ?? (++S.speakerPreviewToken);

        S.speakerErrors.delete(voiceCode);
        setSpeakerPlayerState({
            audio,
            code: voiceCode,
            status: 'loading',
        });
        dispatchSpeakerPreviewState();

        const playAttempt = audio.play();

        if (playAttempt && typeof playAttempt.then === 'function') {
            playAttempt
                .then(() => {
                    if (requestToken !== S.speakerPreviewToken || S.speakerPlayer?.audio !== audio) {
                        return;
                    }

                    S.speakerErrors.delete(voiceCode);
                    setSpeakerPlayerState({
                        audio,
                        code: voiceCode,
                        status: 'playing',
                    });
                    dispatchSpeakerPreviewState();
                })
                .catch(() => {
                    if (requestToken !== S.speakerPreviewToken || S.speakerPlayer?.audio !== audio) {
                        return;
                    }

                    if (typeof fallback === 'function') {
                        fallback(requestToken);
                        return;
                    }

                    S.speakerErrors.set(voiceCode, 'Preview unavailable for this voice.');
                    setSpeakerPlayerState({
                        audio,
                        code: voiceCode,
                        status: pausedStateOnError ? 'paused' : 'idle',
                    });
                    dispatchSpeakerPreviewState();
                });

            return;
        }

        S.speakerErrors.delete(voiceCode);
        setSpeakerPlayerState({
            audio,
            code: voiceCode,
            status: 'playing',
        });
        dispatchSpeakerPreviewState();
    }

    function attemptSpeakerPreview(code, url, token = null) {
        const voiceCode = String(code || '');
        const requestToken = token ?? (++S.speakerPreviewToken);
        const sourceUrl = String(url || '');

        if (!voiceCode || !sourceUrl) {
            if (requestToken !== S.speakerPreviewToken) {
                return;
            }

            finalizeSpeakerPreviewError(voiceCode, 'Preview unavailable for this voice.');
            return;
        }

        const audio = new Audio(sourceUrl);
        audio.preload = 'auto';

        audio.onended = () => {
            if (requestToken !== S.speakerPreviewToken || S.speakerPlayer?.audio !== audio) {
                return;
            }

            stopSpeakerPreview();
        };

        audio.onerror = () => {
            if (requestToken !== S.speakerPreviewToken || S.speakerPlayer?.audio !== audio) {
                return;
            }

            detachSpeakerAudioEvents(audio);

            try { audio.pause(); } catch (_) {}
            finalizeSpeakerPreviewError(voiceCode, 'Preview unavailable for this voice.');
        };

        audio.onpause = () => {
            if (requestToken !== S.speakerPreviewToken || S.speakerPlayer?.audio !== audio) {
                return;
            }

            setSpeakerPlayerState({
                audio,
                code: voiceCode,
                status: audio.currentTime > 0 ? 'paused' : 'idle',
                urls: [sourceUrl],
                index: 0,
            });
            dispatchSpeakerPreviewState();
        };

        audio.onplaying = () => {
            if (requestToken !== S.speakerPreviewToken || S.speakerPlayer?.audio !== audio) {
                return;
            }

            S.speakerErrors.delete(voiceCode);
            setSpeakerPlayerState({
                audio,
                code: voiceCode,
                status: 'playing',
                urls: [sourceUrl],
                index: 0,
            });
            dispatchSpeakerPreviewState();
        };

        setSpeakerPlayerState({
            audio,
            code: voiceCode,
            status: 'loading',
            urls: [sourceUrl],
            index: 0,
        });

        playSpeakerAudio(audio, voiceCode, null, { token: requestToken });
    }

    window.xttsToggleSpeakerPreview = function (code, url) {
        const voiceCode = String(code || '');
        const sourceUrl = String(url || '');
        const player = S.speakerPlayer || {};
        const currentCode = String(player.code || '');
        const currentAudio = player.audio || null;
        const currentStatus = String(player.status || 'idle');

        if (!voiceCode || !sourceUrl) {
            finalizeSpeakerPreviewError(voiceCode, 'Preview unavailable for this voice.');
            return;
        }

        if (voiceCode === currentCode && currentAudio) {
            if (currentStatus === 'loading') {
                stopSpeakerPreview();
                return;
            }

            if (currentStatus === 'playing') {
                try {
                    currentAudio.pause();
                } catch (_) {
                    stopSpeakerPreview();
                }

                return;
            }

            if (currentStatus === 'paused') {
                playSpeakerAudio(currentAudio, voiceCode, null, {
                    token: S.speakerPreviewToken,
                    pausedStateOnError: true,
                });
                return;
            }
        }

        stopSpeakerPreview({ notify: false });
        S.speakerErrors.delete(voiceCode);
        attemptSpeakerPreview(voiceCode, sourceUrl);
    };

    window.xttsSpeakerPreviewSnapshot = speakerPreviewSnapshot;
    window.xttsSpeakerPicker = function (config = {}) {
        return {
            selected: String(config.selected || ''),
            speakers: config.groups || { female: [], male: [] },
            groupOrder: Array.isArray(config.group_order) ? config.group_order : [],
            messages: config.messages || {},
            speakerIndex: Object.create(null),
            avatarErrors: Object.create(null),
            previewState: speakerPreviewSnapshot(),

            init() {
                this.previewState = window.xttsSpeakerPreviewSnapshot();
                this.buildSpeakerIndex();
                this.selected = String(this.selected || this.$refs.speakerSelect?.value || '');
                this.syncNativeSelect(this.selected, false);
            },

            buildSpeakerIndex() {
                const index = Object.create(null);

                ['female', 'male'].forEach((groupKey) => {
                    (this.speakers[groupKey] || []).forEach((speaker) => {
                        index[String(speaker.code)] = speaker;
                    });
                });

                this.speakerIndex = index;
            },

            groupSpeakers(groupKey) {
                return this.speakers[groupKey] || [];
            },

            speakerCount(groupKey) {
                return this.groupSpeakers(groupKey).length;
            },

            speakerFor(code) {
                return this.speakerIndex[String(code || '')] || null;
            },

            syncNativeSelect(code, shouldDispatch = true) {
                const value = String(code || '');
                const select = this.$refs.speakerSelect;

                if (!select) {
                    return;
                }

                select.value = value;

                if (shouldDispatch) {
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                }
            },

            syncSelectedFromNative(value) {
                this.selected = String(value || '');
            },

            selectSpeaker(code) {
                const nextCode = String(code || '');

                if (!nextCode || nextCode === String(this.selected || '')) {
                    return;
                }

                this.selected = nextCode;
                this.syncNativeSelect(nextCode);
            },

            selectedLabel() {
                return this.speakerFor(this.selected)?.display_name || '';
            },

            isSelected(code) {
                return String(this.selected || '') === String(code || '');
            },

            isPlaying(code) {
                return String(this.previewState.code || '') === String(code || '');
            },

            isPaused(code) {
                return String(this.previewState.pausedCode || '') === String(code || '');
            },

            isLoading(code) {
                return String(this.previewState.loadingCode || '') === String(code || '');
            },

            hasPreview(code) {
                return Boolean(this.speakerFor(code)?.has_preview);
            },

            previewUrl(code) {
                return String(this.speakerFor(code)?.preview_url || '');
            },

            previewSpeakerName(code) {
                return this.speakerFor(code)?.display_name || 'speaker';
            },

            showAvatar(speaker) {
                return Boolean(speaker?.avatar_url) && !this.avatarErrors[String(speaker.code || '')];
            },

            markAvatarError(code, element = null) {
                const voiceCode = String(code || '');
                this.avatarErrors = {
                    ...this.avatarErrors,
                    [voiceCode]: true,
                };

                if (element) {
                    element.removeAttribute('src');
                }
            },

            setPreviewError(code, message) {
                const voiceCode = String(code || '');
                const errors = {
                    ...(this.previewState.errors || {}),
                };

                if (message) {
                    errors[voiceCode] = message;
                } else {
                    delete errors[voiceCode];
                }

                this.previewState = {
                    ...this.previewState,
                    errors,
                };
            },

            togglePreview(code) {
                const voiceCode = String(code || '');
                const url = this.previewUrl(voiceCode);

                if (!this.hasPreview(voiceCode)) {
                    this.setPreviewError(
                        voiceCode,
                        this.messages.previewUnavailable || 'Preview unavailable for this voice.'
                    );
                    return;
                }

                this.setPreviewError(voiceCode, '');
                window.xttsToggleSpeakerPreview(voiceCode, url);
            },

            syncPreviewState(detail) {
                this.previewState = detail || window.xttsSpeakerPreviewSnapshot();
            },

            previewError(code) {
                const errors = this.previewState?.errors || {};
                return errors[String(code || '')] || '';
            },

            previewButtonIcon(code) {
                if (!this.hasPreview(code)) {
                    return 'ri-volume-mute-fill';
                }

                if (this.isLoading(code)) {
                    return 'ri-edit-circle-line';
                }

                if (this.isPlaying(code)) {
                    return 'ri-pause-line';
                }

                return 'ri-play-line';
            },

            previewButtonText(code) {
                if (!this.hasPreview(code)) {
                    return this.messages.previewUnavailableShort || 'No preview';
                }

                if (this.isLoading(code)) {
                    return this.messages.previewLoadingShort || 'Loading...';
                }

                if (this.isPlaying(code)) {
                    return this.messages.previewPauseShort || 'Pause preview';
                }

                if (this.isPaused(code)) {
                    return this.messages.previewResumeShort || 'Resume preview';
                }

                if (this.previewError(code)) {
                    return this.messages.previewRetryShort || 'Retry preview';
                }

                return this.messages.previewPlayShort || 'Play preview';
            },

            previewStatusText(code) {
                if (!this.hasPreview(code)) {
                    return this.messages.previewUnavailable || 'Preview unavailable for this voice.';
                }

                if (this.isLoading(code)) {
                    return this.messages.previewLoadingHint || 'Loading preview audio...';
                }

                if (this.isPlaying(code)) {
                    return this.messages.previewPlayingHint || 'Preview is playing now.';
                }

                if (this.isPaused(code)) {
                    return this.messages.previewPausedHint || 'Preview paused. Tap again to resume.';
                }

                if (this.previewError(code)) {
                    return this.messages.previewRetryHint || 'Preview could not be loaded. Tap again to retry.';
                }

                return this.messages.previewReadyHint || 'Tap play to hear a short sample.';
            },

            previewLabel(code) {
                const name = this.previewSpeakerName(code);
                let template = this.messages.previewPlay || 'Play preview for :speaker';

                if (!this.hasPreview(code)) {
                    return this.messages.previewUnavailable || 'Preview unavailable for this voice.';
                }

                if (this.isLoading(code)) {
                    return this.messages.previewLoadingHint || 'Loading preview audio...';
                }

                if (this.isPlaying(code)) {
                    template = this.messages.previewPause || 'Pause preview for :speaker';
                } else if (this.isPaused(code)) {
                    template = this.messages.previewResume || 'Resume preview for :speaker';
                } else if (this.previewError(code)) {
                    template = this.messages.previewRetry || 'Retry preview for :speaker';
                }

                return template.replace(':speaker', name);
            },
        };
    };

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

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Blob URL management Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
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

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ WaveSurfer lifecycle Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
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
        S.previewInit.delete(jobId);

        const wave = document.getElementById('xtts-wave-' + jobId);
        if (wave) wave.innerHTML = '';
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

        const ph = document.getElementById('xtts-ph-' + jobId);
        const wave = document.getElementById('xtts-wave-' + jobId);
        const time = document.getElementById('xtts-time-' + jobId);

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
            console.error('[XTTS] WaveSurfer error', jobId, e);
        });

        (async () => {
            try {
                const blobUrl = await getBlobUrl(jobId, url);
                ws.load(blobUrl);
            } catch (e) {
                console.warn('[XTTS] Falling back to direct URL', jobId, e);
                ws.load(url);
            }
        })();

        S.previewWS.set(jobId, ws);
        return ws;
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Button binding Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    function bindPreviewButtons() {
        document.querySelectorAll('.btn-xtts-preview[data-job][data-url]').forEach(btn => {
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

        document.querySelectorAll('.btn-xtts-stop[data-job]').forEach(btn => {
            if (btn.dataset.bound === '1') return;
            btn.dataset.bound = '1';

            btn.addEventListener('click', () => {
                stopWS(S.previewWS.get(btn.getAttribute('data-job')));
            });
        });
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Preload + render waveforms eagerly Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    async function preloadAndRenderRecentAudio() {
        const buttons = Array.from(
            document.querySelectorAll('.btn-xtts-preview[data-job][data-url]')
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
                console.error('[XTTS] Preload failed', jobId, e);
            }
        }
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Auto-scroll & highlight latest render after job completes Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    function highlightLatestRender() {
        const firstCard = document.querySelector('.render-card');
        if (!firstCard) return;

        firstCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        firstCard.style.transition = 'box-shadow 0.3s ease';
        firstCard.style.boxShadow = '0 0 0 3px var(--bs-success, #198754)';

        setTimeout(() => {
            firstCard.style.boxShadow = '';
        }, 2500);

        const btn = firstCard.querySelector('.btn-xtts-preview[data-job][data-url]');
        if (btn) {
            const jobId = btn.getAttribute('data-job');
            const url = btn.getAttribute('data-url');
            if (jobId && url && !S.previewWS.has(jobId)) {
                initPreview(jobId, url, true);
            }
        }
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ SPA state helpers Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
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
            console.warn('[XTTS] SPA restore failed', e);
        }
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Form persistence helpers Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    function formSave() {
        try {
            const lw = getLivewireComponent();
            if (!lw) return;

            const state = {
                text: lw.get('text') ?? '',
                speaker_id: lw.get('speaker_id') ?? 'liza',
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
            lw.set('speaker_id', saved.speaker_id ?? 'liza');
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
            console.warn('[XTTS] Form restore failed', e);
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
            'speaker_id',
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

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Livewire event listeners Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    function registerLivewireEvents() {
        if (!window.Livewire || S.eventsBound) return;
        S.eventsBound = true;

        Livewire.on('xtts-job-started', (data) => {
            spaSave(data);
            formSave();
        });

        Livewire.on('xtts-job-state-sync', (data) => {
            const saved = spaLoad();
            if (saved && saved.jobId === data.jobId) {
                spaSave({ ...saved, ...data });
            }
        });

        Livewire.on('xtts-job-state-clear', () => {
            spaClear();
        });

        Livewire.on('xtts-form-state-clear', () => {
            formClear();
        });

        Livewire.on('xtts-job-completed', () => {
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

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Page boot Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    function bootXttsPage() {
        const runBoot = () => {
            const root = document.getElementById('xtts-page-root');
            if (!root) return;

            registerLivewireEvents();
            bindPreviewButtons();
            spaRestoreIfNeeded();
            formRestoreIfNeeded();
            watchAndPersistForm();

        };

        setTimeout(runBoot, 0);
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Cleanup on navigation Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    function teardownXttsPage() {
        formSave();
        stopSpeakerPreview({ notify: false });

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

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Initialise Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    document.addEventListener('livewire:initialized', bootXttsPage);
    document.addEventListener('livewire:navigated', bootXttsPage);
    document.addEventListener('livewire:navigating', teardownXttsPage);

    window.addEventListener('beforeunload', () => {
        formSave();
        stopSpeakerPreview({ notify: false });

        S.previewWS.forEach(ws => {
            try { ws.destroy(); } catch (_) {}
        });
        S.previewWS.clear();
    });
})();
</script>
<script>
window.addEventListener('xtts-form-state-clear', () => {
    try {
        localStorage.removeItem('xtts_form_state_v1');
    } catch (_) {}
});
</script>
@endpush
