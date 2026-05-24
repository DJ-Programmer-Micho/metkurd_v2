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
    use \App\Support\Plans\ResolvesConcurrentJobLimit;

    protected string $toolCode = 'xomni';
    protected string $actionCode = 'generate';
    protected string $fullActionCode = 'xomni.generate';
    protected string $voiceEngine = 'xomni';

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

    public string $speaker_id = '';
    public string $language = 'ckb';
    public array $availableSpeakers = [];
    public array $speakerPickerPayload = [];
    public array $speakerCatalog = [];

    protected array $supportedLanguages = ['ckb', 'en', 'ar'];

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
    #[On('xomni-renders-refresh')]
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
        $this->dismissedJobStatusFor = session('xomni.dismissed_job_status_for');
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

    public function hydrateSpeakerCatalog($value = null): void
    {
        $this->speakerCatalog = $this->resolveSpeakerCatalog();
        $this->availableSpeakers = $this->resolveAvailableSpeakers();
        $this->speakerPickerPayload = $this->buildSpeakerPickerData();
    }

    protected function resolveCurrentPlanId(): int
    {
        $customer = auth('app')->user();
        if (!$customer) {
            return 0;
        }

        $planId = method_exists($customer, 'currentServicePlanId')
            ? (int) ($customer->currentServicePlanId() ?? 0)
            : 0;

        if ($planId <= 0) {
            $planId = (int) ($customer->service_plan_id ?? 0);
        }

        if (!$planId) {
            return 0;
        }

        return (int) $planId;
    }

    protected function resolveSpeakerCatalog(): array
    {
        $planId = $this->resolveCurrentPlanId();

        if ($planId <= 0) {
            return [];
        }

        return Voice::query()
            ->leftJoin('plan_voice_access as pva', function ($join) use ($planId) {
                $join->on('pva.voice_id', '=', 'voices.id')
                    ->where('pva.service_plan_id', '=', $planId);
            })
            ->where('voices.meta->engine', $this->voiceEngine)
            ->where(function ($query) {
                $query->where('voices.is_public', true)
                    ->orWhere('pva.is_active', true);
            })
            ->orderBy('voices.sort_order')
            ->orderBy('voices.id')
            ->get([
                'voices.code',
                'voices.name',
                'voices.is_active',
                'voices.meta',
            ])
            ->map(function (Voice $voice): array {
                $meta = (array) ($voice->meta ?? []);
                $code = (string) $voice->code;
                $displayName = $this->speakerDisplayName($code, (string) $voice->name);
                $group = $this->speakerGroupKey($code, (string) $voice->name, $meta, []);
                $groupLabel = $this->speakerGroupLabel($group);
                $refAudio = $this->normalizeRunpodRefAudioPath((string) data_get($meta, 'ref_audio', data_get($meta, 'runpod_ref_audio', '')));
                $style = trim((string) data_get($meta, 'style', ''));
                $styleLabel = $style !== ''
                    ? Str::of($style)->replace(['_', '-'], ' ')->headline()->value()
                    : null;
                $previewAudio = trim((string) data_get($meta, 'preview_audio', data_get($meta, 'preview_audio_path', '')));
                $avatarPath = trim((string) data_get($meta, 'avatar_path', data_get($meta, 'avatar', '')));
                $hasAvatar = $avatarPath !== '';
                $isActive = (bool) $voice->is_active && $refAudio !== '';

                return [
                    'code' => $code,
                    'display_name' => $displayName,
                    'subtitle' => $this->speakerSubtitle($displayName, $meta),
                    'group' => $group,
                    'group_label' => $groupLabel,
                    'gender' => str_starts_with($group, 'female') ? 'female' : (str_starts_with($group, 'male') ? 'male' : 'custom'),
                    'gender_label' => $groupLabel,
                    'style' => $style,
                    'style_label' => $styleLabel,
                    'avatar_url' => $hasAvatar ? $this->speakerAvatarRoute($code) : null,
                    'avatar_initials' => $this->speakerAvatarInitials($displayName),
                    'has_preview' => $previewAudio !== '',
                    'preview_url' => $this->speakerPreviewRoute($code),
                    'is_active' => $isActive,
                    'ref_audio' => $refAudio,
                ];
            })
            ->filter(fn (array $speaker): bool => trim((string) ($speaker['ref_audio'] ?? '')) !== '')
            ->values()
            ->all();
    }

    protected function resolveAvailableSpeakers(): array
    {
        return collect($this->speakerCatalog)
            ->filter(fn (array $speaker): bool => (bool) ($speaker['is_active'] ?? false))
            ->filter(fn (array $speaker): bool => trim((string) ($speaker['ref_audio'] ?? '')) !== '')
            ->mapWithKeys(fn (array $speaker): array => [
                (string) ($speaker['code'] ?? '') => (string) ($speaker['display_name'] ?? $speaker['code'] ?? ''),
            ])
            ->filter(fn (string $label, string $code): bool => $code !== '' && $label !== '')
            ->all();
    }

    protected function buildSpeakerPickerData(): array
    {
        $groups = $this->buildSpeakerPickerGroups();
        $groupOrder = $this->speakerGroupOrder();

        return [
            'selected' => (string) $this->speaker_id,
            'expanded' => $this->resolveExpandedSpeakerGroup($groups),
            'groups' => $groups,
            'group_order' => $groupOrder,
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
                'expand' => __('Expand'),
                'collapse' => __('Collapse'),
                'availableStyles' => __('Available styles'),
                'styles' => __('Styles'),
                'voiceCategories' => __('Voice categories'),
                'scrollVoiceCategories' => __('Scroll voice categories'),
                'selectedVoice' => __('Selected voice'),
                'voiceStyle' => __('Voice style'),
            ],
        ];
    }

    protected function buildSpeakerPickerGroups(): array
    {
        $groups = collect($this->speakerGroupOrder())
            ->mapWithKeys(fn (array $item): array => [(string) $item['key'] => []])
            ->all();

        foreach ($this->speakerCatalog as $speaker) {
            $group = (string) ($speaker['group'] ?? 'custom');

            if (!array_key_exists($group, $groups)) {
                $group = 'custom';
            }

            $groups[$group][] = $speaker;
        }

        return $groups;
    }

    protected function speakerGroupOrder(): array
    {
        return [
            ['key' => 'male_1', 'label' => __('Male 1')],
            ['key' => 'male_2', 'label' => __('Male 2')],
            ['key' => 'male_3', 'label' => __('Male 3')],
            ['key' => 'female_1', 'label' => __('Female 1')],
            ['key' => 'female_2', 'label' => __('Female 2')],
            ['key' => 'female_3', 'label' => __('Female 3')],
            ['key' => 'custom', 'label' => __('Custom')],
        ];
    }

    /**
     * @param array<string, array<int, array<string,mixed>>> $groups
     */
    protected function resolveExpandedSpeakerGroup(array $groups): string
    {
        $selectedCode = (string) $this->speaker_id;

        if ($selectedCode !== '') {
            foreach ($groups as $groupKey => $speakers) {
                foreach ((array) $speakers as $speaker) {
                    if ((string) ($speaker['code'] ?? '') === $selectedCode) {
                        return (string) $groupKey;
                    }
                }
            }
        }

        foreach ($this->speakerGroupOrder() as $order) {
            $key = (string) ($order['key'] ?? '');

            if ($key !== '' && !empty($groups[$key])) {
                return $key;
            }
        }

        return 'custom';
    }

    protected function syncSpeakerPickerSelection(): void
    {
        if ($this->speakerPickerPayload === []) {
            return;
        }

        $this->speakerPickerPayload['selected'] = (string) $this->speaker_id;
        $this->speakerPickerPayload['expanded'] = $this->resolveExpandedSpeakerGroup((array) ($this->speakerPickerPayload['groups'] ?? []));
    }

    protected function speakerGroupKey(string $code, string $voiceName, array $meta, array $currentGroups = []): string
    {
        $speakerGroup = Str::of((string) data_get($meta, 'speaker_group'))
            ->lower()
            ->trim()
            ->replace(['-', ' '], '_')
            ->value();

        if (in_array($speakerGroup, ['male_1', 'male_2', 'male_3', 'female_1', 'female_2', 'female_3', 'custom'], true)) {
            return $speakerGroup;
        }

        $gender = Str::of((string) data_get($meta, 'gender'))
            ->lower()
            ->trim()
            ->value();

        if ($gender === 'male') {
            return 'male_1';
        }

        if ($gender === 'female') {
            return 'female_1';
        }

        if ($gender === 'custom') {
            return 'custom';
        }

        $haystack = Str::of($code . ' ' . $voiceName)
            ->lower()
            ->replace(['-', '_'], ' ')
            ->value();

        if (str_contains($haystack, 'female 3') || str_contains($haystack, 'bebe')) {
            return 'female_3';
        }

        if (str_contains($haystack, 'female 2') || str_contains($haystack, 'liza')) {
            return 'female_2';
        }

        if (str_contains($haystack, 'female 1') || str_contains($haystack, 'patty')) {
            return 'female_1';
        }

        if (str_contains($haystack, 'male 3') || str_contains($haystack, 'marcel')) {
            return 'male_3';
        }

        if (str_contains($haystack, 'male 2') || str_contains($haystack, 'shabo')) {
            return 'male_2';
        }

        if (str_contains($haystack, 'male 1') || str_contains($haystack, 'hyder') || str_contains($haystack, 'male')) {
            return 'male_1';
        }

        if (str_contains($haystack, 'female') || str_contains($haystack, 'woman')) {
            return 'female_1';
        }

        if (str_contains($haystack, 'custom')) {
            return 'custom';
        }

        return 'custom';
    }

    protected function speakerGroupLabel(string $group): string
    {
        return match ($group) {
            'male_1' => __('Male 1'),
            'male_2' => __('Male 2'),
            'male_3' => __('Male 3'),
            'female_1' => __('Female 1'),
            'female_2' => __('Female 2'),
            'female_3' => __('Female 3'),
            default => __('Custom'),
        };
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
        return route('app.xomni.speaker.preview', [
            'locale' => $locale ?: $this->speakerMediaLocale(),
            'voiceCode' => $code,
        ]);
    }

    protected function speakerAvatarRoute(string $code, ?string $locale = null): string
    {
        return route('app.xomni.speaker.avatar', [
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
            'language' => ['required', 'string', 'in:' . implode(',', $this->supportedLanguages)],
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
            session(['xomni.dismissed_job_status_for' => $this->currentJobId]);
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

    protected function selectedRefAudioPath(): string
    {
        foreach ($this->speakerCatalog as $speaker) {
            if ((string) ($speaker['code'] ?? '') !== (string) $this->speaker_id) {
                continue;
            }

            return $this->normalizeRunpodRefAudioPath((string) ($speaker['ref_audio'] ?? ''));
        }

        return '';
    }

    protected function normalizeRunpodRefAudioPath(string $value): string
    {
        $normalized = Str::of($value)
            ->replace('\\', '/')
            ->trim()
            ->ltrim('/')
            ->value();

        if ($normalized === '') {
            return '';
        }

        if (preg_match('~(?:^|/)runpod-volume/ref_voices/(.+)$~i', $normalized, $matches)) {
            $normalized = $matches[1];
        } elseif (preg_match('~(?:^|/)ref_voices/(.+)$~i', $normalized, $matches)) {
            $normalized = $matches[1];
        }

        return trim($normalized);
    }

    protected function normalizedLanguage(): string
    {
        $language = strtolower(trim((string) $this->language));

        return in_array($language, $this->supportedLanguages, true) ? $language : 'ckb';
    }

    public function postXomni(RunPodProvider $runpod, CreditService $credits): void
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
            $this->dispatch('alert', type: 'error', message: __('Your plan does not allow Apollo 1.5v.'));
            return;
        }

        if ($this->isGenerating()) {
            $this->dispatch('alert', type: 'warning', message: __('A generation is already in progress.'));
            return;
        }

        $this->validate();

        $text = trim((string) $this->text);
        $language = $this->normalizedLanguage();
        $refAudio = $this->selectedRefAudioPath();

        if ($refAudio === '') {
            $this->dispatch('alert', type: 'error', message: __('Please select a reference voice.'));
            return;
        }

        $chars = $this->currentChars;

        $cost = method_exists($c, 'priceCreditsFor')
            ? (int) $c->priceCreditsFor($actionCode, [
                'chars' => $chars,
                'metric_code' => 'character',
                'language' => $language,
                'speaker_id' => $this->speaker_id,
            ])
            : (int) ceil($chars * 1.0);

        if ($cost <= 0) {
            $this->dispatch('alert', type: 'error', message: __('Pricing is not configured.'));
            return;
        }

        try {
            $credits->charge((int) $c->id, $cost, 'xomni_charge', [
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
        session()->forget('xomni.dismissed_job_status_for');
        MlJob::create([
            'id'             => $jobId,
            'customer_id'    => $c->id,
            'tool_id'        => $tool->id,
            'tool_action_id' => $action->id,
            'job_kind'       => 'xomni',
            'status'         => 'queued',
            'provider'       => 'runpod',
            'input' => [
                'mode' => 'builtin_ref',
                'text' => $text,
                'speaker_id' => $this->speaker_id,
                'ref_audio' => $refAudio,
                'ref_text' => '',
                'language' => $language,
                'output_format' => 'wav',
                'return_base64' => true,
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
            $endpointId = data_get($tool->meta, 'runpod_endpoint_id') ?: config('runpod.endpoints.omni');

            if (!$endpointId) {
                throw new \RuntimeException(__('RUNPOD_ENDPOINT_ID_OMNI is missing.'));
            }

            $timeout = (int) (data_get($tool->meta, 'runpod_timeout') ?: config('runpod.timeout', 60));

            $resp = $runpod->run($endpointId, [
                'mode' => 'builtin_ref',
                'text' => $text,
                'ref_audio' => $refAudio,
                'ref_text' => '',
                'language' => $language,
                'output_format' => 'wav',
                'return_base64' => true,
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
            $this->dispatch('xomni-renders-refresh');

            $this->dispatch('xomni-job-started', [
                'jobId'       => $jobId,
                'providerJobId' => $rpId,
                'status'      => 'running',
                'progress'    => 20,
            ]);

            $this->dispatch('alert', type: 'success', message: __('RunPod job started.'));
        } catch (\Throwable $e) {
            $this->dispatch('header:refresh');

            $credits->refund((int) $c->id, $cost, 'xomni_refund', [
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
            $this->dispatch('xomni-job-state-clear');
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

            $this->dispatch('xomni-job-state-sync', [
                'jobId' => $this->currentJobId,
                'status' => $this->currentStatus,
                'progress' => $this->currentProgress,
            ]);

            if (!empty($result['done'])) {
                $this->syncWallet();
                $this->dispatch('customerPlanUpdated');
                $this->dispatch('customerStorageUpdated');
                $this->dispatch('xomni-renders-refresh');
                $this->dispatch('xomni-job-completed');
                $this->dispatch('xomni-job-state-clear');
                $this->dispatch('alert', type: 'success', message: __('Done'));
            }

            if (!empty($result['failed'])) {
                $this->dispatch('header:refresh');
                $this->dispatch('xomni-job-state-clear');
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
            $this->dispatch('xomni-job-state-clear');
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
        $this->speaker_id = array_key_first($this->availableSpeakers) ?? '';
        $this->language = 'ckb';
        $this->split = true;
        $this->max_words = 25;
        $this->fade_ms = 80;

        $this->selectedPreset = 'balanced';
        $this->applyPreset($this->selectedPreset);

        $this->syncSpeakerPickerSelection();
        $this->syncCostPreview();

        $this->dispatch('xomni-form-state-clear');
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
            $this->dispatch('xomni-job-state-clear');
            $this->dispatch('xomni-form-state-clear');
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
        $this->dispatch('xomni-job-state-clear');
        $this->dispatch('xomni-form-state-clear');
        $this->dispatch('xomni-renders-refresh');
        $this->dispatch('alert', type: 'warning', message: __('Current job eliminated. Credits were not refunded.'));
    }
    
    public function render()
    {
        return view('app.pages.omni.⚡app-xomni');
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

    <x-slot:title>{{ __('Apollo 1.5v') }} | {{ __('MET KURD') }}</x-slot:title>

    <div id="xomni-page-root">
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
        $gpuQueueMessage = __('MetKurd AI GPUs are currently busy. Your job is queued and will start automatically as soon as capacity is available.');
        $gpuQueueMessageDir = in_array(app()->getLocale(), ['ar', 'ku']) ? 'rtl' : 'ltr';
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
                            <div class="fw-semibold">{{ __('Apollo 1.5v Job Status') }}</div>
                            <div class="small text-muted">{{ __('Job ID:') }} {{ $currentJobId ?: '-' }}</div>
                        </div>
                        <span class="badge text-bg-{{ $badge }}">{{ $status }}</span>
                    </div>

                    <div class="progress" role="progressbar" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar progress-bar-striped {{ !$jobFinished ? 'progress-bar-animated' : '' }} bg-{{ $badge }}" style="width: {{ $progress }}%"></div>
                    </div>

                    @if($currentStatus === 'queued')
                        <div dir="{{ $gpuQueueMessageDir }}" class="text-danger mt-2" style="font-size: 20px">{{ $gpuQueueMessage }}</div>
                    @endif

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
                                <strong>{{ __('Apollo 1.5v') }} ({{ __('OmniVoice TTS') }})</strong>
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
                            <div class="mt-1">
                                <button
                                        type="button"
                                        id="xomniTipsButton"
                                        class="btn btn-outline-info mb-3"
                                        data-bs-toggle="modal"
                                        data-bs-target="#xomniTipsModal"
                                    >
                                        {{ __('Tips') }}
                                </button>
                                <br>
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
                                <div class="d-flex gap-2 mt-4 flex-wrap">
                                    <button
                                        class="btn {{ $this->canGenerate ? 'btn-primary' : 'btn-danger' }}"
                                        wire:click="postXomni"
                                        wire:loading.attr="disabled"
                                        wire:target="postXomni"
                                        @disabled(!$this->canGenerate)
                                        type="button"
                                        id="btn-xomni-generate"
                                    >
                                        <span wire:loading.remove wire:target="postXomni">
                                            {{ $this->canGenerate ? __('Generate') : ($this->generateBlockedReason ?? __('Generate')) }}
                                        </span>
                                        <span wire:loading wire:target="postXomni">
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
                                @error('text')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            <hr>

                            @php($speakerPicker = $speakerPickerPayload)

                            <div class="row g-3 align-items-start">
                                <div class="col-12">
                                    <div
                                        class="xomni-speaker-picker omni-reference-panel"
                                        x-data="xomniSpeakerPicker(@js($speakerPicker))"
                                        x-init="init()"
                                        x-on:xomni-speaker-preview-state.window="syncPreviewState($event.detail)"
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
                                                <label class="form-label mb-1">{{ __('Reference voice') }}</label>
                                                <div class="text-muted small">{{ __('Select a voice') }}</div>
                                            </div>

                                            <div class="small text-muted">
                                                <span class="xomni-speaker-selection-pill omni-voice-selection-pill" x-text="selectedLabel() || messages.noSpeakerSelected"></span>
                                            </div>
                                        </div>
                                        <template x-if="!flatSpeakers().length">
                                            <div class="omni-voice-empty">
                                                {{ __('No voices available in this group yet.') }}
                                            </div>
                                        </template>

                                        <div class="omni-category-wrap mt-1">
                                            <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                                <div class="small text-muted" x-text="messages.voiceCategories || '{{ __('Voice categories') }}'"></div>
                                                <div class="omni-category-nav d-flex align-items-center gap-1">
                                                    <button type="button" class="omni-category-nav-btn" x-on:click="scrollGroups('prev')" :aria-label="messages.scrollVoiceCategories || '{{ __('Scroll voice categories') }}'">
                                                        <i class="ri-arrow-left-s-line"></i>
                                                    </button>
                                                    <button type="button" class="omni-category-nav-btn" x-on:click="scrollGroups('next')" :aria-label="messages.scrollVoiceCategories || '{{ __('Scroll voice categories') }}'">
                                                        <i class="ri-arrow-right-s-line"></i>
                                                    </button>
                                                </div>
                                            </div>

                                            <div class="omni-category-slider py-1" x-ref="groupSlider" role="tablist" aria-label="{{ __('Scroll voice categories') }}">
                                                <template x-for="group in orderedGroups()" :key="group.key">
                                                    <button
                                                        type="button"
                                                        class="omni-category-chip"
                                                        :class="{ 'is-active': isGroupExpanded(group.key), 'has-selected': groupHasSelected(group.key), 'is-empty': groupCount(group.key) === 0 }"
                                                        x-on:click="selectGroup(group.key)"
                                                        :data-group-key="group.key"
                                                        :aria-selected="isGroupExpanded(group.key) ? 'true' : 'false'"
                                                        role="tab"
                                                    >
                                                        <span class="omni-category-title" x-text="group.label"></span>
                                                        <span class="omni-category-count">
                                                            <span x-text="groupCount(group.key)"></span>
                                                            <span x-text="messages.styles"></span>
                                                        </span>
                                                        <template x-if="groupHasSelected(group.key)">
                                                            <span class="omni-category-selected">{{ __('Selected') }}</span>
                                                        </template>
                                                    </button>
                                                </template>
                                            </div>
                                        </div>

                                        <template x-if="expandedGroupSpeakers().length">
                                            <div class="omni-voice-group-panel mt-3" :id="'xomni-group-' + expandedGroup()">
                                                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                                                    <div>
                                                        <div class="omni-voice-group-heading" x-text="groupLabel(expandedGroup())"></div>
                                                        <div class="small text-muted">
                                                            <span x-text="messages.availableStyles"></span>
                                                            <span class="mx-1">:</span>
                                                            <span x-text="groupCount(expandedGroup())"></span>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="omni-voice-grid" role="radiogroup" aria-label="{{ __('Speaker voice picker') }}">
                                                    <template x-for="speaker in expandedGroupSpeakers()" :key="speaker.code">
                                                        <div
                                                            class="omni-voice-card"
                                                            :class="{ 'is-selected': isSelected(speaker.code), 'is-disabled': !isSelectable(speaker.code) }"
                                                            role="radio"
                                                            :tabindex="isSelectable(speaker.code) ? 0 : -1"
                                                            :aria-checked="isSelected(speaker.code) ? 'true' : 'false'"
                                                            :aria-disabled="!isSelectable(speaker.code) ? 'true' : 'false'"
                                                            x-on:click="selectSpeaker(speaker.code)"
                                                            x-on:keydown.enter.prevent="selectSpeaker(speaker.code)"
                                                            x-on:keydown.space.prevent="selectSpeaker(speaker.code)"
                                                        >
                                                            <div class="omni-voice-card-top d-flex align-items-start gap-2">
                                                                <div class="omni-voice-avatar">
                                                                    <img
                                                                        x-cloak
                                                                        x-show="showAvatar(speaker)"
                                                                        :src="speaker.avatar_url || ''"
                                                                        :alt="speaker.display_name"
                                                                        class="omni-voice-avatar-image"
                                                                        loading="lazy"
                                                                        x-on:error="markAvatarError(speaker.code, $event.target)"
                                                                    >

                                                                    <span
                                                                        x-cloak
                                                                        x-show="!showAvatar(speaker)"
                                                                        class="omni-voice-avatar-fallback"
                                                                        x-text="speaker.avatar_initials"
                                                                    ></span>
                                                                </div>

                                                                <div class="flex-grow-1 min-w-0">
                                                                    <div class="d-flex align-items-start justify-content-between gap-2">
                                                                        <div class="min-w-0">
                                                                            <div class="omni-voice-title text-break" x-text="speaker.display_name"></div>

                                                                            <div class="omni-voice-tags mt-1">
                                                                                <template x-if="speaker.style_label">
                                                                                    <span class="omni-voice-tag omni-voice-tag--style" x-text="speaker.style_label"></span>
                                                                                </template>
                                                                                <template x-if="!isSelectable(speaker.code)">
                                                                                    <span class="omni-voice-tag omni-voice-tag--muted">{{ __('Unavailable') }}</span>
                                                                                </template>
                                                                            </div>
                                                                        </div>

                                                                        <div class="omni-voice-check" x-show="isSelected(speaker.code)" aria-hidden="true">
                                                                            <i class="fa fa-check-circle"></i>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>

                                                            <div class="omni-voice-card-footer d-flex align-items-center justify-content-between gap-2 flex-wrap mt-2">
                                                                <div class="d-flex align-items-center gap-2 flex-wrap min-w-0">
                                                                    <template x-if="isSelected(speaker.code)">
                                                                        <span class="omni-voice-state omni-voice-state--selected">{{ __('Selected') }}</span>
                                                                    </template>

                                                                    <template x-if="isPlaying(speaker.code)">
                                                                        <span class="omni-voice-state omni-voice-state--live">{{ __('Previewing') }}</span>
                                                                    </template>

                                                                    <template x-if="isPaused(speaker.code)">
                                                                        <span class="omni-voice-state">{{ __('Paused') }}</span>
                                                                    </template>

                                                                    <span class="omni-voice-preview-meta" aria-live="polite" x-text="previewStatusCompact(speaker.code)"></span>
                                                                </div>

                                                                <button
                                                                    type="button"
                                                                    class="omni-voice-preview-btn"
                                                                    :class="{ 'is-loading': isLoading(speaker.code), 'is-playing': isPlaying(speaker.code), 'is-paused': isPaused(speaker.code), 'is-error': Boolean(previewError(speaker.code)) }"
                                                                    x-on:click.stop="togglePreview(speaker.code)"
                                                                    :aria-label="previewLabel(speaker.code)"
                                                                    :title="previewLabel(speaker.code)"
                                                                    :aria-pressed="isPlaying(speaker.code) ? 'true' : 'false'"
                                                                    :aria-busy="isLoading(speaker.code) ? 'true' : 'false'"
                                                                    :disabled="!hasPreview(speaker.code) || !isSelectable(speaker.code)"
                                                                >
                                                                    <span class="omni-voice-preview-icon" aria-hidden="true">
                                                                        <i class="fa" :class="previewButtonIcon(speaker.code)"></i>
                                                                    </span>
                                                                    <span class="omni-voice-preview-text" x-text="previewButtonText(speaker.code)"></span>
                                                                </button>
                                                            </div>

                                                            <template x-if="previewError(speaker.code)">
                                                                <div class="small text-warning mt-1" x-text="previewError(speaker.code)"></div>
                                                            </template>
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>

                                        <template x-if="expandedGroup() && !expandedGroupSpeakers().length">
                                            <div class="omni-voice-empty mt-3">
                                                {{ __('No voices available in this group yet.') }}
                                            </div>
                                        </template>

                                        <div class="text-muted small mt-3">
                                            {{ __('Only one speaker preview plays at a time.') }}
                                        </div>
                                    </div>

                                    @error('speaker_id')
                                        <div class="text-danger small mt-2">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">{{ __('Language') }}</label>
                                    <select class="form-select" wire:model.change="language">
                                        <option value="ckb">{{ __('Kurdish / Sorani') }}</option>
                                        <option value="en">{{ __('English') }}</option>
                                        <option value="ar">{{ __('Arabic') }}</option>
                                    </select>
                                    @error('language')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <livewire:partials.xtts-renders-panel
            tool-code="xomni"
            event-prefix="xomni"
            stream-route="app.renders.xomni.stream"
            download-route="app.renders.xomni.download"
            dom-prefix="xomni"
            page-name="xomniRendersPage"
            panel-title="Recent Renders"
            model-label="Apollo 1.5v"
        />
    </div>
    <div class="modal fade" id="xomniTipsModal" tabindex="-1" aria-labelledby="xomniTipsModalLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 bg-transparent">
                <h5 id="xomniTipsModalLabel" class="visually-hidden">{{ __('Tips') }}</h5>
                <div class="card ribbon-box border shadow-none mb-lg-0">
                    <div class="card-body text-muted">
                        <div class="ribbon-three ribbon-three-success"><span>{{ __('Tips') }}</span></div>

                        <div class="table-responsive mt-5">
                            <table class="table table-sm table-bordered align-middle mb-0">
                                <tbody>
                                    <tr>
                                        <th scope="row" class="w-25">{{ __('Date') }}</th>
                                        <td>{{ __('1/12/2025') }}</td>
                                    </tr>
                                    <tr>
                                        <th scope="row">{{ __('Time') }}</th>
                                        <td>{{ __('10:30') }}</td>
                                    </tr>
                                    <tr>
                                        <th scope="row">{{ __('Math') }}</th>
                                        <td>{{ __('5+5=10') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="text-end mt-3">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('Close') }}</button>
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
<style>
    .xomni-speaker-picker [x-cloak] {
        display: none !important;
    }

    .omni-reference-panel {
        padding: 1rem;
        border: 1px solid rgba(148, 163, 184, 0.16);
        border-radius: 1.1rem;
        background: linear-gradient(180deg, rgba(15, 23, 42, 0.72), rgba(15, 23, 42, 0.5));
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
        backdrop-filter: blur(8px);
    }

    .omni-voice-selection-pill {
        display: inline-flex;
        align-items: center;
        padding: 0.45rem 0.8rem;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, 0.18);
        background: rgba(2, 6, 23, 0.62);
        color: rgba(226, 232, 240, 0.96);
        font-weight: 500;
    }

    .omni-category-wrap {
        position: relative;
    }

    .omni-category-nav-btn {
        width: 1.8rem;
        height: 1.8rem;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, 0.24);
        background: rgba(15, 23, 42, 0.75);
        color: rgba(226, 232, 240, 0.88);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.72rem;
        transition: border-color 0.18s ease, color 0.18s ease;
    }

    .omni-category-nav-btn:hover {
        border-color: rgba(96, 165, 250, 0.5);
        color: #fff;
    }

    .omni-category-slider {
        display: flex;
        gap: 0.75rem;
        overflow-x: auto;
        padding-bottom: 0.3rem;
        scroll-snap-type: x proximity;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;
        scrollbar-color: rgba(148, 163, 184, 0.35) transparent;
    }

    .omni-category-slider::-webkit-scrollbar {
        height: 7px;
    }

    .omni-category-slider::-webkit-scrollbar-thumb {
        background: rgba(148, 163, 184, 0.35);
        border-radius: 999px;
    }

    .omni-category-slider::-webkit-scrollbar-track {
        background: transparent;
    }

    .omni-category-chip {
        position: relative;
        flex: 0 0 auto;
        min-width: 145px;
        border-radius: 1rem;
        border: 1px solid rgba(148, 163, 184, 0.2);
        background: rgba(15, 23, 42, 0.72);
        color: rgba(226, 232, 240, 0.94);
        padding: 0.64rem 0.78rem;
        text-align: left;
        scroll-snap-align: start;
        transition: border-color 0.18s ease, background 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
    }

    .omni-category-chip:hover {
        border-color: rgba(96, 165, 250, 0.44);
        background: rgba(17, 24, 39, 0.92);
        transform: translateY(-1px);
    }

    .omni-category-chip:focus-visible {
        outline: none;
        border-color: rgba(96, 165, 250, 0.62);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
    }

    .omni-category-chip.is-active {
        border-color: rgba(59, 130, 246, 0.88);
        background: rgba(59, 130, 246, 0.12);
        box-shadow: 0 0 0 1px rgba(59, 130, 246, 0.14);
    }

    .omni-category-chip.has-selected {
        border-color: rgba(96, 165, 250, 0.78);
    }

    .omni-category-chip.is-empty {
        opacity: 0.68;
    }

    .omni-category-title {
        display: block;
        font-size: 0.82rem;
        font-weight: 650;
        line-height: 1.2;
    }

    .omni-category-count {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        margin-top: 0.28rem;
        color: rgba(203, 213, 225, 0.84);
        font-size: 0.7rem;
    }

    .omni-category-selected {
        display: inline-flex;
        align-items: center;
        margin-top: 0.4rem;
        padding: 0.14rem 0.42rem;
        border-radius: 999px;
        border: 1px solid rgba(59, 130, 246, 0.3);
        background: rgba(59, 130, 246, 0.16);
        color: rgba(191, 219, 254, 0.98);
        font-size: 0.64rem;
        font-weight: 600;
        line-height: 1;
    }

    .omni-voice-group-panel {
        border: 1px solid rgba(148, 163, 184, 0.14);
        border-radius: 0.95rem;
        padding: 0.72rem;
        background: rgba(2, 6, 23, 0.38);
    }

    .omni-voice-group-heading {
        font-size: 0.9rem;
        font-weight: 650;
        color: rgba(241, 245, 249, 0.96);
        line-height: 1.2;
    }

    .omni-voice-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
    }

    .omni-voice-card {
        position: relative;
        padding: 0.66rem 0.72rem;
        border: 1px solid rgba(148, 163, 184, 0.15);
        border-radius: 0.9rem;
        background: rgba(15, 23, 42, 0.76);
        cursor: pointer;
        transition: border-color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
    }

    .omni-voice-card:hover {
        transform: translateY(-1px);
        border-color: rgba(96, 165, 250, 0.42);
        background: rgba(17, 24, 39, 0.92);
    }

    .omni-voice-card:focus-visible {
        outline: none;
        border-color: rgba(96, 165, 250, 0.58);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.16);
    }

    .omni-voice-card.is-selected {
        border-color: rgba(96, 165, 250, 0.82);
        background: linear-gradient(180deg, rgba(30, 41, 59, 0.94), rgba(15, 23, 42, 0.92));
        box-shadow: 0 0 0 1px rgba(96, 165, 250, 0.18), 0 10px 22px rgba(2, 6, 23, 0.24);
    }

    .omni-voice-card.is-disabled {
        cursor: not-allowed;
        opacity: 0.62;
        filter: grayscale(0.35);
    }

    .omni-voice-card.is-disabled:hover {
        transform: none;
        border-color: rgba(148, 163, 184, 0.15);
        background: rgba(15, 23, 42, 0.76);
    }

    .omni-voice-card-top {
        min-height: 2.8rem;
    }

    .omni-voice-avatar {
        width: 2.45rem;
        height: 2.45rem;
        flex: 0 0 2.45rem;
        border-radius: 1.85rem;
        border: 1px solid rgba(148, 163, 184, 0.16);
        background: linear-gradient(180deg, rgba(51, 65, 85, 0.88), rgba(15, 23, 42, 0.96));
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.05);
        overflow: hidden;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .omni-voice-avatar-image {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .omni-voice-avatar-fallback {
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

    .omni-voice-title {
        font-weight: 600;
        color: rgba(241, 245, 249, 0.98);
        line-height: 1.24;
        font-size: 0.86rem;
    }

    .omni-voice-tags {
        display: inline-flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.42rem;
    }

    .omni-voice-tag,
    .omni-voice-state {
        display: inline-flex;
        align-items: center;
        padding: 0.18rem 0.42rem;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, 0.18);
        background: rgba(255, 255, 255, 0.04);
        color: rgba(226, 232, 240, 0.9);
        font-size: 0.66rem;
        line-height: 1.1;
    }

    .omni-voice-subtitle {
        line-height: 1.25;
    }

    .omni-voice-tag {
        background: rgba(59, 130, 246, 0.12);
        color: rgba(191, 219, 254, 0.96);
    }

    .omni-voice-tag--muted {
        border-color: rgba(148, 163, 184, 0.2);
        background: rgba(71, 85, 105, 0.24);
        color: rgba(203, 213, 225, 0.92);
    }

    .omni-voice-tag--style {
        border-color: rgba(125, 211, 252, 0.24);
        background: rgba(14, 116, 144, 0.2);
        color: rgba(186, 230, 253, 0.98);
    }

    .omni-voice-state--selected {
        border-color: rgba(59, 130, 246, 0.24);
        background: rgba(59, 130, 246, 0.15);
        color: rgba(191, 219, 254, 0.98);
    }

    .omni-voice-state--live {
        border-color: rgba(34, 197, 94, 0.22);
        background: rgba(34, 197, 94, 0.14);
        color: rgba(187, 247, 208, 0.98);
    }

    .omni-voice-card-footer {
        margin-top: 0.48rem !important;
        row-gap: 0.45rem !important;
    }

    .omni-voice-preview-meta {
        color: rgba(203, 213, 225, 0.76);
        font-size: 0.68rem;
        line-height: 1.2;
    }

    .omni-voice-preview-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.42rem;
        min-height: 2.05rem;
        padding: 0.44rem 0.68rem;
        border: 1px solid rgba(148, 163, 184, 0.18);
        border-radius: 0.8rem;
        background: rgba(2, 6, 23, 0.62);
        color: #f8fafc;
        font-weight: 580;
        font-size: 0.76rem;
        transition: border-color 0.18s ease, background 0.18s ease, color 0.18s ease, transform 0.18s ease;
    }

    .omni-voice-preview-btn {
        width: auto;
        flex: 0 0 auto;
        white-space: nowrap;
    }

    .omni-voice-preview-btn:hover:not(:disabled) {
        transform: translateY(-1px);
        border-color: rgba(96, 165, 250, 0.44);
        color: #ffffff;
    }

    .omni-voice-preview-btn:focus-visible {
        outline: none;
        border-color: rgba(96, 165, 250, 0.58);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.14);
    }

    .omni-voice-preview-btn.is-playing {
        background: var(--bs-primary, #0d6efd);
        border-color: var(--bs-primary, #0d6efd);
        color: #ffffff;
    }

    .omni-voice-preview-btn.is-loading {
        border-color: rgba(148, 163, 184, 0.3);
        background: rgba(30, 41, 59, 0.88);
    }

    .omni-voice-preview-btn.is-paused {
        border-color: rgba(96, 165, 250, 0.36);
        background: rgba(30, 41, 59, 0.7);
    }

    .omni-voice-preview-btn.is-error:not(:disabled) {
        border-color: rgba(245, 158, 11, 0.3);
        color: rgba(253, 224, 71, 0.96);
    }

    .omni-voice-preview-btn:disabled {
        opacity: 0.52;
        cursor: not-allowed;
    }

    .omni-voice-preview-icon {
        width: 1rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.85rem;
        flex: 0 0 1rem;
    }

    .omni-voice-preview-text {
        display: inline-block;
        line-height: 1.15;
    }

    .omni-voice-check {
        color: rgba(96, 165, 250, 0.96);
        font-size: 1.05rem;
        line-height: 1;
        flex: 0 0 auto;
    }

    .omni-voice-empty {
        padding: 0.9rem;
        border: 1px dashed rgba(148, 163, 184, 0.18);
        border-radius: 0.9rem;
        color: rgba(148, 163, 184, 0.92);
        background: rgba(15, 23, 42, 0.42);
        font-size: 0.92rem;
        text-align: center;
    }

    @media (max-width: 992px) {
        .omni-category-chip {
            min-width: 138px;
        }

        .omni-voice-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 767.98px) {
        .omni-reference-panel {
            padding: 0.85rem;
        }

        .omni-category-chip {
            min-width: 128px;
            padding: 0.58rem 0.64rem;
        }

        .omni-voice-card {
            padding: 0.62rem 0.66rem;
        }

        .omni-voice-avatar {
            width: 2.25rem;
            height: 2.25rem;
            flex-basis: 2.25rem;
        }

        .omni-category-nav-btn {
            width: 1.65rem;
            height: 1.65rem;
        }

        .omni-voice-preview-btn {
            width: 100%;
        }
    }

    @media (max-width: 576px) {
        .omni-category-chip {
            min-width: 118px;
        }

        .omni-voice-grid {
            grid-template-columns: 1fr;
        }
    }
</style>
@endpush

@push('scripts')
<script src="https://unpkg.com/wavesurfer.js@7/dist/wavesurfer.min.js"></script>
<script>
(function () {
    'use strict';

    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Singleton namespace ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
    if (!window.__xomni_WAVE__) window.__xomni_WAVE__ = {};
    const S = window.__xomni_WAVE__;

    S.previewWS     = S.previewWS     || new Map(); // jobId ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ WaveSurfer
    S.previewMeta   = S.previewMeta   || new Map(); // jobId ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ { url, blobUrl }
    S.previewInit   = S.previewInit   || new Set(); // jobIds already preloaded
    S.pendingFetch  = S.pendingFetch  || new Map(); // url ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ Promise<Blob>
    S.eventsBound   = S.eventsBound   || false;
    S.commitHooked  = S.commitHooked  || false;
    S.formWatchBoot = S.formWatchBoot || false;
    S.speakerPlayer = S.speakerPlayer || { audio: null, code: null, status: 'idle', urls: [], index: 0 };
    S.speakerErrors = S.speakerErrors || new Map();
    S.speakerPreviewToken = S.speakerPreviewToken || 0;

    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Cache config ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
    const CACHE_NAME    = 'xomni-audio-v4';
    const CACHE_MAX     = 30;
    const PRELOAD_LIMIT = 10;

    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ SPA / navigation persistence via localStorage ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
    const SPA_KEY  = 'xomni_spa_job';
    const FORM_KEY = 'xomni_form_state_v1';

    function safeNumber(value, fallback) {
        const n = Number(value);
        return Number.isFinite(n) ? n : fallback;
    }
    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Helpers ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
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

        const root = document.getElementById('xomni-page-root');
        if (!root) return null;

        const wireId = root.getAttribute('wire:id');
        if (!wireId) return null;

        try {
            return window.Livewire.find(wireId);
        } catch (_) {
            return null;
        }
    }

    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Audio Cache ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
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
        window.dispatchEvent(new CustomEvent('xomni-speaker-preview-state', {
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

    function withPreviewProxyParam(url, value) {
        const rawUrl = String(url || '');

        try {
            const parsed = new URL(rawUrl, window.location.origin);

            if (value === null) {
                parsed.searchParams.delete('proxy');
            } else {
                parsed.searchParams.set('proxy', String(value));
            }

            if (/^https?:\/\//i.test(rawUrl)) {
                return parsed.toString();
            }

            return `${parsed.pathname}${parsed.search}${parsed.hash}`;
        } catch (_) {
            return rawUrl;
        }
    }

    function speakerPreviewCandidateUrls(url) {
        const rawUrl = String(url || '').trim();

        if (!rawUrl) {
            return [];
        }

        const candidates = [rawUrl];
        const hasProxyOne = /([?&])proxy=1(?:[&#]|$)/.test(rawUrl);
        const hasProxyZero = /([?&])proxy=0(?:[&#]|$)/.test(rawUrl);

        if (hasProxyOne) {
            candidates.push(withPreviewProxyParam(rawUrl, 0));
            candidates.push(withPreviewProxyParam(rawUrl, null));
        } else if (hasProxyZero) {
            candidates.push(withPreviewProxyParam(rawUrl, 1));
            candidates.push(withPreviewProxyParam(rawUrl, null));
        } else {
            candidates.push(withPreviewProxyParam(rawUrl, 1));
            candidates.push(withPreviewProxyParam(rawUrl, 0));
        }

        return Array.from(new Set(candidates.filter(Boolean)));
    }

    function previewPlaybackErrorMessage(playError = null, audio = null) {
        const mediaErrorCode = Number(audio?.error?.code || 0);
        const errorName = String(playError?.name || '').trim();

        if (errorName === 'NotAllowedError') {
            return 'Playback was blocked by your browser. Tap preview again.';
        }

        if (mediaErrorCode === 4) {
            return 'Preview format is not supported on this device.';
        }

        if (mediaErrorCode === 2) {
            return 'Preview download failed. Please check your network and retry.';
        }

        if (mediaErrorCode === 3) {
            return 'Preview decoding failed on this browser.';
        }

        if (errorName === 'AbortError') {
            return 'Preview playback was interrupted. Please try again.';
        }

        return 'Preview could not be played. Please try again.';
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
                .catch((playError) => {
                    if (requestToken !== S.speakerPreviewToken || S.speakerPlayer?.audio !== audio) {
                        return;
                    }

                    if (typeof fallback === 'function') {
                        fallback(requestToken, playError);
                        return;
                    }

                    S.speakerErrors.set(voiceCode, previewPlaybackErrorMessage(playError, audio));
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
        const sourceCandidates = Array.isArray(url)
            ? url.map((value) => String(value || '').trim()).filter(Boolean)
            : speakerPreviewCandidateUrls(url);
        const sourceUrl = String(sourceCandidates[0] || '');
        const fallbackCandidates = sourceCandidates.slice(1);

        if (!voiceCode || !sourceUrl) {
            if (requestToken !== S.speakerPreviewToken) {
                return;
            }

            finalizeSpeakerPreviewError(voiceCode, 'Preview source is unavailable for this voice.');
            return;
        }

        const audio = new Audio(sourceUrl);
        audio.preload = 'auto';

        const fallbackToNextSource = (incomingToken = requestToken, playError = null) => {
            if (incomingToken !== S.speakerPreviewToken) {
                return false;
            }

            if (fallbackCandidates.length <= 0) {
                finalizeSpeakerPreviewError(voiceCode, previewPlaybackErrorMessage(playError, audio));
                return false;
            }

            attemptSpeakerPreview(voiceCode, fallbackCandidates, incomingToken);

            return true;
        };

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
            fallbackToNextSource(requestToken);
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
            urls: sourceCandidates,
            index: 0,
        });

        playSpeakerAudio(audio, voiceCode, fallbackToNextSource, { token: requestToken });
    }

    window.xomniToggleSpeakerPreview = function (code, url) {
        const voiceCode = String(code || '');
        const sourceUrl = String(url || '');
        const player = S.speakerPlayer || {};
        const currentCode = String(player.code || '');
        const currentAudio = player.audio || null;
        const currentStatus = String(player.status || 'idle');

        if (!voiceCode || !sourceUrl) {
            finalizeSpeakerPreviewError(voiceCode, 'Preview source is unavailable for this voice.');
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

    window.xomniSpeakerPreviewSnapshot = speakerPreviewSnapshot;
    window.xomniSpeakerPicker = function (config = {}) {
        return {
            selected: String(config.selected || ''),
            expanded: String(config.expanded || ''),
            speakers: config.groups || { male_1: [], male_2: [], male_3: [], female_1: [], female_2: [], female_3: [], custom: [] },
            groupOrder: Array.isArray(config.group_order) ? config.group_order : [],
            messages: config.messages || {},
            speakerIndex: Object.create(null),
            speakerList: [],
            avatarErrors: Object.create(null),
            previewState: speakerPreviewSnapshot(),

            init() {
                this.previewState = window.xomniSpeakerPreviewSnapshot();
                this.buildSpeakerIndex();
                this.selected = String(this.selected || this.$refs.speakerSelect?.value || '');

                if (!this.isSelectable(this.selected)) {
                    this.selected = this.firstSelectableCode();
                }

                this.syncNativeSelect(this.selected, false);
                this.expanded = String(
                    this.expanded
                    || this.groupOfSpeaker(this.selected)
                    || this.firstPopulatedGroup()
                );

                this.$nextTick(() => {
                    this.scrollChipIntoView(this.expanded);
                });
            },

            buildSpeakerIndex() {
                const index = Object.create(null);
                const ordered = [];
                const keys = this.orderedGroupKeys();

                keys.forEach((groupKey) => {
                    (this.speakers[groupKey] || []).forEach((speaker) => {
                        index[String(speaker.code)] = speaker;
                        ordered.push(speaker);
                    });
                });

                this.speakerIndex = index;
                this.speakerList = ordered;
            },

            orderedGroupKeys() {
                const groupKeys = this.groupOrder.map((item) => String(item?.key || '')).filter(Boolean);
                return groupKeys.length ? groupKeys : Object.keys(this.speakers || {});
            },

            orderedGroups() {
                return this.orderedGroupKeys().map((key) => ({
                    key,
                    label: this.groupLabel(key),
                }));
            },

            groupLabel(groupKey) {
                const match = (this.groupOrder || []).find((item) => String(item?.key || '') === String(groupKey || ''));
                return String(match?.label || groupKey || '');
            },

            speakersForGroup(groupKey) {
                const key = String(groupKey || '');
                const speakers = this.speakers?.[key];
                return Array.isArray(speakers) ? speakers : [];
            },

            groupCount(groupKey) {
                return this.speakersForGroup(groupKey).length;
            },

            firstPopulatedGroup() {
                return this.orderedGroupKeys()
                    .find((groupKey) => this.groupCount(groupKey) > 0) || '';
            },

            groupOfSpeaker(code) {
                return String(this.speakerFor(code)?.group || '');
            },

            ensureExpandedGroup() {
                const current = String(this.expanded || '');
                if (current && this.groupCount(current) > 0) {
                    return;
                }

                this.expanded = String(
                    this.groupOfSpeaker(this.selected)
                    || this.firstPopulatedGroup()
                );
            },

            expandedGroup() {
                if (String(this.expanded || '') === '__none__') {
                    return '';
                }

                this.ensureExpandedGroup();
                return String(this.expanded || '');
            },

            expandedGroupSpeakers() {
                const groupKey = this.expandedGroup();
                return this.speakersForGroup(groupKey);
            },

            isGroupExpanded(groupKey) {
                return String(this.expandedGroup()) === String(groupKey || '');
            },

            selectGroup(groupKey) {
                const next = String(groupKey || '');

                if (!next) {
                    return;
                }

                this.expanded = next;
                this.$nextTick(() => {
                    this.scrollChipIntoView(next);
                });
            },

            scrollGroups(direction = 'next') {
                const slider = this.$refs.groupSlider;

                if (!slider) {
                    return;
                }

                const multiplier = direction === 'prev' ? -1 : 1;
                const offset = Math.max(180, Math.floor(slider.clientWidth * 0.78));

                slider.scrollBy({
                    left: multiplier * offset,
                    behavior: 'smooth',
                });
            },

            scrollChipIntoView(groupKey) {
                const key = String(groupKey || '');
                const slider = this.$refs.groupSlider;

                if (!key || !slider) {
                    return;
                }

                const chip = Array.from(slider.querySelectorAll('[data-group-key]'))
                    .find((el) => String(el?.dataset?.groupKey || '') === key);

                if (!chip || typeof chip.scrollIntoView !== 'function') {
                    return;
                }

                chip.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest',
                    inline: 'center',
                });
            },

            groupHasSelected(groupKey) {
                return this.groupOfSpeaker(this.selected) === String(groupKey || '');
            },

            flatSpeakers() {
                return Array.isArray(this.speakerList) ? this.speakerList : [];
            },

            speakerFor(code) {
                return this.speakerIndex[String(code || '')] || null;
            },

            firstSelectableCode() {
                return this.flatSpeakers()
                    .find((speaker) => this.isSelectable(speaker?.code))
                    ?.code || '';
            },

            isSelectable(code) {
                return Boolean(this.speakerFor(code)?.is_active);
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
                this.expanded = this.groupOfSpeaker(this.selected) || this.expanded;
                this.$nextTick(() => {
                    this.scrollChipIntoView(this.expanded);
                });
            },

            selectSpeaker(code) {
                const nextCode = String(code || '');

                if (!nextCode || !this.isSelectable(nextCode) || nextCode === String(this.selected || '')) {
                    return;
                }

                this.selected = nextCode;
                this.expanded = this.groupOfSpeaker(nextCode) || this.expanded;
                this.syncNativeSelect(nextCode);
                this.$nextTick(() => {
                    this.scrollChipIntoView(this.expanded);
                });
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

                if (!this.isSelectable(voiceCode)) {
                    this.setPreviewError(
                        voiceCode,
                        this.messages.previewUnavailable || 'Preview unavailable for this voice.'
                    );
                    return;
                }

                if (!this.isSelected(voiceCode)) {
                    this.selectSpeaker(voiceCode);
                }

                if (!this.hasPreview(voiceCode)) {
                    this.setPreviewError(
                        voiceCode,
                        this.messages.previewUnavailable || 'Preview unavailable for this voice.'
                    );
                    return;
                }

                this.setPreviewError(voiceCode, '');
                window.xomniToggleSpeakerPreview(voiceCode, url);
            },

            syncPreviewState(detail) {
                this.previewState = detail || window.xomniSpeakerPreviewSnapshot();
            },

            previewError(code) {
                const errors = this.previewState?.errors || {};
                return errors[String(code || '')] || '';
            },

            previewButtonIcon(code) {
                if (!this.isSelectable(code) || !this.hasPreview(code)) {
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
                if (!this.isSelectable(code) || !this.hasPreview(code)) {
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

            previewStatusCompact(code) {
                if (!this.isSelectable(code) || !this.hasPreview(code)) {
                    return this.messages.previewUnavailableShort || 'No preview';
                }

                if (this.isLoading(code)) {
                    return this.messages.previewLoadingShort || 'Loading...';
                }

                if (this.isPlaying(code)) {
                    return this.messages.previewPlayingHint || 'Previewing';
                }

                if (this.isPaused(code)) {
                    return this.messages.previewPausedHint || 'Paused';
                }

                if (this.previewError(code)) {
                    return this.messages.previewRetryShort || 'Retry preview';
                }

                return this.messages.previewReadyHint || 'Tap to preview.';
            },

            previewStatusText(code) {
                if (!this.isSelectable(code) || !this.hasPreview(code)) {
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

                if (!this.isSelectable(code) || !this.hasPreview(code)) {
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

    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Blob URL management ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
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
        const existingMeta = S.previewMeta.get(jobId) || {};
        S.previewMeta.set(jobId, { ...existingMeta, url, blobUrl });

        return blobUrl;
    }

    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ WaveSurfer lifecycle ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
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

        const wave = document.getElementById('xomni-wave-' + jobId);
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
        jobId = String(jobId || '').trim();
        url = String(url || '').trim();

        if (!jobId || !url) return null;

        const existingWave = document.getElementById('xomni-wave-' + jobId);
        const existingMeta = S.previewMeta.get(jobId);

        if (S.previewWS.has(jobId)) {
            const sameContainer = existingMeta?.waveEl && existingWave && existingMeta.waveEl === existingWave && existingMeta.waveEl.isConnected;
            const sameUrl = String(existingMeta?.url || '') === url;

            if (sameContainer && sameUrl) {
                return S.previewWS.get(jobId);
            }

            destroyPreview(jobId);
        }

        const ph = document.getElementById('xomni-ph-' + jobId);
        const wave = existingWave || document.getElementById('xomni-wave-' + jobId);
        const time = document.getElementById('xomni-time-' + jobId);

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
            console.error('[xomni] WaveSurfer error', jobId, e);
        });

        (async () => {
            try {
                const blobUrl = await getBlobUrl(jobId, url);
                const cachedMeta = S.previewMeta.get(jobId) || {};
                S.previewMeta.set(jobId, { ...cachedMeta, url, waveEl: wave });
                ws.load(blobUrl);
            } catch (e) {
                console.warn('[xomni] Falling back to direct URL', jobId, e);
                const cachedMeta = S.previewMeta.get(jobId) || {};
                S.previewMeta.set(jobId, { ...cachedMeta, url, waveEl: wave });
                ws.load(url);
            }
        })();

        const cachedMeta = S.previewMeta.get(jobId) || {};
        S.previewMeta.set(jobId, { ...cachedMeta, url, waveEl: wave });
        S.previewWS.set(jobId, ws);
        return ws;
    }

    function previewButtonEntries() {
        return Array.from(document.querySelectorAll('.btn-xomni-preview[data-job][data-url]'))
            .map((btn, index) => ({
                btn,
                jobId: String(btn.getAttribute('data-job') || '').trim(),
                url: String(btn.getAttribute('data-url') || '').trim(),
                isLatest: btn.getAttribute('data-latest') === '1',
                rank: Number(btn.getAttribute('data-preload-rank') ?? index),
            }))
            .filter((entry) => entry.jobId !== '' && entry.url !== '');
    }

    function reconcilePreviewInstances() {
        const entries = previewButtonEntries();
        const active = new Map(entries.map((entry) => [entry.jobId, entry]));

        Array.from(S.previewWS.keys()).forEach((jobId) => {
            const entry = active.get(jobId);

            if (!entry) {
                destroyPreview(jobId);
                return;
            }

            const meta = S.previewMeta.get(jobId) || {};
            const wave = document.getElementById('xomni-wave-' + jobId);
            const sameContainer = meta.waveEl && wave && meta.waveEl === wave && meta.waveEl.isConnected;
            const sameUrl = String(meta.url || '') === entry.url;

            if (!sameContainer || !sameUrl) {
                destroyPreview(jobId);
            }
        });

        entries.forEach((entry) => {
            if (!S.previewWS.has(entry.jobId)) {
                initPreview(entry.jobId, entry.url, entry.isLatest);
            }
        });
    }

    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Button binding ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
    function bindPreviewButtons() {
        document.querySelectorAll('.btn-xomni-preview[data-job][data-url]').forEach(btn => {
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

        document.querySelectorAll('.btn-xomni-stop[data-job]').forEach(btn => {
            if (btn.dataset.bound === '1') return;
            btn.dataset.bound = '1';

            btn.addEventListener('click', () => {
                stopWS(S.previewWS.get(btn.getAttribute('data-job')));
            });
        });
    }

    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Preload + render waveforms eagerly ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
    async function preloadAndRenderRecentAudio() {
        const buttons = previewButtonEntries()
            .sort((a, b) => a.rank - b.rank)
            .slice(0, PRELOAD_LIMIT);

        for (const btn of buttons) {
            const jobId = btn.jobId;
            const url = btn.url;
            const isLatest = btn.isLatest;

            if (!jobId || !url) continue;
            if (S.previewWS.has(jobId)) continue;

            try {
                getBlob(url).catch(() => {});
                initPreview(jobId, url, isLatest);
                S.previewInit.add(jobId);
            } catch (e) {
                console.error('[xomni] Preload failed', jobId, e);
            }
        }
    }

    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Auto-scroll & highlight latest render after job completes ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
    function highlightLatestRender() {
        const firstCard = document.querySelector('.render-card');
        if (!firstCard) return;

        firstCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        firstCard.style.transition = 'box-shadow 0.3s ease';
        firstCard.style.boxShadow = '0 0 0 3px var(--bs-success, #198754)';

        setTimeout(() => {
            firstCard.style.boxShadow = '';
        }, 2500);

        const btn = firstCard.querySelector('.btn-xomni-preview[data-job][data-url]');
        if (btn) {
            const jobId = btn.getAttribute('data-job');
            const url = btn.getAttribute('data-url');
            if (jobId && url && !S.previewWS.has(jobId)) {
                initPreview(jobId, url, true);
            }
        }
    }

    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ SPA state helpers ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
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
            console.warn('[xomni] SPA restore failed', e);
        }
    }

    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Form persistence helpers ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
    function formSave() {
        try {
            const lw = getLivewireComponent();
            if (!lw) return;

            const state = {
                text: lw.get('text') ?? '',
                speaker_id: lw.get('speaker_id') ?? '',
                language: lw.get('language') ?? 'ckb',
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
            lw.set('speaker_id', saved.speaker_id ?? '');
            lw.set('language', saved.language ?? 'ckb');
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
            console.warn('[xomni] Form restore failed', e);
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

    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Livewire event listeners ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
    function registerLivewireEvents() {
        if (!window.Livewire || S.eventsBound) return;
        S.eventsBound = true;

        Livewire.on('xomni-job-started', (data) => {
            spaSave(data);
            formSave();
        });

        Livewire.on('xomni-job-state-sync', (data) => {
            const saved = spaLoad();
            if (saved && saved.jobId === data.jobId) {
                spaSave({ ...saved, ...data });
            }
        });

        Livewire.on('xomni-job-state-clear', () => {
            spaClear();
        });

        Livewire.on('xomni-form-state-clear', () => {
            formClear();
        });

        Livewire.on('xomni-job-completed', () => {
            spaClear();
            requestAnimationFrame(() => {
                bindPreviewButtons();
                reconcilePreviewInstances();
                preloadAndRenderRecentAudio().catch(() => {});
                highlightLatestRender();
            });
        });

        if (!S.commitHooked && typeof Livewire.hook === 'function') {
            S.commitHooked = true;

            Livewire.hook('commit', ({ succeed }) => {
                succeed(() => {
                    requestAnimationFrame(() => {
                        bindPreviewButtons();
                        reconcilePreviewInstances();
                        preloadAndRenderRecentAudio().catch(() => {});
                        formSave();
                    });
                });
            });
        }
    }

    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Page boot ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
    function bootxomniPage() {
        const runBoot = () => {
            const root = document.getElementById('xomni-page-root');
            if (!root) return;

            registerLivewireEvents();
            bindPreviewButtons();
            reconcilePreviewInstances();
            preloadAndRenderRecentAudio().catch(() => {});
            spaRestoreIfNeeded();
            formRestoreIfNeeded();
            watchAndPersistForm();

        };

        setTimeout(runBoot, 0);
    }

    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Cleanup on navigation ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
    function teardownxomniPage() {
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

    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Initialise ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
    document.addEventListener('livewire:initialized', bootxomniPage);
    document.addEventListener('livewire:navigated', bootxomniPage);
    document.addEventListener('livewire:navigating', teardownxomniPage);

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
window.addEventListener('xomni-form-state-clear', () => {
    try {
        localStorage.removeItem('xomni_form_state_v1');
    } catch (_) {}
});
</script>
@endpush


