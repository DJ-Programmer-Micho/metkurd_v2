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

    protected const NFE_STEP_MIN = 1;
    protected const NFE_STEP_MAX = 128;
    protected const CFG_STRENGTH_MIN = 0.0;
    protected const CFG_STRENGTH_MAX = 10.0;
    protected const SPEED_MIN = 0.1;
    protected const SPEED_MAX = 2.0;

    protected string $toolCode = 'ftts';
    protected string $actionCode = 'standard';
    protected string $fullActionCode = 'ftts.standard';
    protected string $voiceEngine = 'ftts';

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
    public array $availableSpeakers = [];
    public array $speakerPickerPayload = [];

    public string $checkpoint = '';
    public string $device = 'auto';
    public bool $use_ema = true;
    public int $nfe_step = 32;
    public float $cfg_strength = 2.0;
    public float $speed = 1.0;
    public bool $remove_silence = false;
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
    public function refreshUi(): void
    {
        $this->syncWallet();
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
        $this->syncCostPreview();
        $this->refreshUi();
    }

    public function mount(): void
    {
        $this->syncWallet();
        $this->hydrateSpeakerCatalog();
        $this->resetF5Settings();

        if (!array_key_exists($this->speaker_id, $this->availableSpeakers)) {
            $this->speaker_id = array_key_first($this->availableSpeakers) ?? '';
        }

        $this->syncSpeakerPickerSelection();
        $this->syncCostPreview();
        $this->dismissedJobStatusFor = session('f5tts.dismissed_job_status_for');
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

    public function updatedNfeStep(): void
    {
        $this->nfe_step = $this->clampInt($this->nfe_step, self::NFE_STEP_MIN, self::NFE_STEP_MAX);
    }

    public function updatedCfgStrength(): void
    {
        $this->cfg_strength = $this->clampFloat($this->cfg_strength, self::CFG_STRENGTH_MIN, self::CFG_STRENGTH_MAX);
    }

    public function updatedSpeed(): void
    {
        $this->speed = $this->clampFloat($this->speed, self::SPEED_MIN, self::SPEED_MAX);
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
            'f5tts-speaker-picker:' . md5(implode('|', $cacheKeyCodes)),
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
        return route('app.f5tts.speaker.preview', [
            'locale' => $locale ?: $this->speakerMediaLocale(),
            'voiceCode' => $code,
        ]);
    }

    protected function speakerAvatarRoute(string $code, ?string $locale = null): string
    {
        return route('app.f5tts.speaker.avatar', [
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

    protected function resetF5Settings(): void
    {
        $toolMeta = (array) (Tool::query()->where('code', $this->toolCode)->value('meta') ?? []);

        $this->checkpoint = '';
        $this->device = 'auto';

        $this->use_ema = (bool) data_get(
            $toolMeta,
            'f5tts.use_ema',
            data_get($toolMeta, 'use_ema', true)
        );

        $this->nfe_step = $this->clampInt((int) data_get(
            $toolMeta,
            'f5tts.nfe_step',
            data_get($toolMeta, 'nfe_step', 32)
        ), self::NFE_STEP_MIN, self::NFE_STEP_MAX);

        $this->cfg_strength = $this->clampFloat((float) data_get(
            $toolMeta,
            'f5tts.cfg_strength',
            data_get($toolMeta, 'cfg_strength', 2.0)
        ), self::CFG_STRENGTH_MIN, self::CFG_STRENGTH_MAX);

        $this->speed = $this->clampFloat((float) data_get(
            $toolMeta,
            'f5tts.speed',
            data_get($toolMeta, 'speed', 1.0)
        ), self::SPEED_MIN, self::SPEED_MAX);

        $this->remove_silence = (bool) data_get(
            $toolMeta,
            'f5tts.remove_silence',
            data_get($toolMeta, 'remove_silence', false)
        );
    }

    protected function normalizeF5Settings(): void
    {
        $this->nfe_step = $this->clampInt($this->nfe_step, self::NFE_STEP_MIN, self::NFE_STEP_MAX);
        $this->cfg_strength = $this->clampFloat($this->cfg_strength, self::CFG_STRENGTH_MIN, self::CFG_STRENGTH_MAX);
        $this->speed = $this->clampFloat($this->speed, self::SPEED_MIN, self::SPEED_MAX);
    }

    protected function clampInt(int|float|string|null $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }

    protected function clampFloat(int|float|string|null $value, float $min, float $max): float
    {
        $numeric = (float) $value;

        return max($min, min($max, $numeric));
    }

    protected function friendlyF5FailureMessage(string $rawMessage): string
    {
        $message = trim($rawMessage);

        if ($message !== '' && str_starts_with($message, '{')) {
            $decoded = json_decode($message, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $decodedMessage = trim((string) data_get($decoded, 'message', ''));

                if ($decodedMessage !== '') {
                    $message = $decodedMessage;
                }
            }
        }

        $message = trim((string) preg_replace('/\s+/u', ' ', $message));

        if ($message === '') {
            return __('Generation failed. Please retry.');
        }

        if (str_contains(strtolower($message), 't must be strictly increasing or decreasing')) {
            return __('We could not generate stable audio timing. Please retry. If it repeats, shorten the text or adjust voice/settings.');
        }

        if (
            str_contains(strtolower($message), 'output.wav_b64')
            || str_contains(strtolower($message), 'completed without output')
        ) {
            return __('Generation finished without a valid audio file. Please retry.');
        }

        return $message;
    }

    #[Computed]
    public function sliders(): array
    {
        return [
            ['key' => 'nfe_step', 'label' => __('NFE Step'), 'min' => 1, 'max' => 128, 'step' => 1, 'val' => $this->nfe_step],
            ['key' => 'cfg_strength', 'label' => __('CFG Strength'), 'min' => 0, 'max' => 10, 'step' => 0.1, 'val' => $this->cfg_strength],
            ['key' => 'speed', 'label' => __('Speed'), 'min' => 0.1, 'max' => 2, 'step' => 0.01, 'val' => $this->speed],
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

    protected function pricingContext(int $chars): array
    {
        return [
            'chars' => $chars,
            'metric_code' => 'character',
            'speaker_key' => $this->speaker_id,
        ];
    }

    protected function calculateCreditsCost(int $chars, $customer = null): int
    {
        if ($chars <= 0) {
            return 0;
        }

        $customer = $customer ?: auth('app')->user();

        if (!$customer) {
            return 0;
        }

        return max(0, (int) $customer->priceCreditsFor($this->fullActionCode, $this->pricingContext($chars)));
    }

    protected function syncCostPreview(): void
    {
        $c = auth('app')->user();
        $chars = $this->currentChars;

        if (!$c || $chars <= 0) {
            $this->creditsCost = 0;
            return;
        }

        $this->creditsCost = $this->calculateCreditsCost($chars, $c);
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
            'text' => ['required', 'string', 'min:1', 'max:' . $this->maxPerSubmit, function (string $attribute, mixed $value, \Closure $fail): void {
                if (trim((string) $value) === '') {
                    $fail(__('Please enter some text.'));
                }
            }],
            'speaker_id' => ['required', 'string', function ($attribute, $value, $fail) {
                if (!array_key_exists((string) $value, $this->availableSpeakers)) {
                    $fail(__('The selected speaker is not available for your plan.'));
                }
            }],
            'use_ema' => ['boolean'],
            'nfe_step' => ['required', 'integer', 'min:' . self::NFE_STEP_MIN, 'max:' . self::NFE_STEP_MAX],
            'cfg_strength' => ['required', 'numeric', 'min:' . self::CFG_STRENGTH_MIN, 'max:' . self::CFG_STRENGTH_MAX],
            'speed' => ['required', 'numeric', 'min:' . self::SPEED_MIN, 'max:' . self::SPEED_MAX],
            'remove_silence' => ['boolean'],
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
            session(['f5tts.dismissed_job_status_for' => $this->currentJobId]);
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

    public function postF5tts(RunPodProvider $runpod, CreditService $credits): void
    {
        $this->normalizeF5Settings();
        $this->showJobStatus = true;
        $c = auth('app')->user();
        $actionCode = $this->fullActionCode;
        $this->hydrateCurrentJobFromDb();
        if ($this->currentActiveJobsCount() >= $this->allowedConcurrentJobs()) {
            $this->dispatch('alert', type: 'warning', message: __('You reached your concurrent job limit for the current plan.'));
            return;
        }
        if (method_exists($c, 'isAllowed') && !$c->isAllowed($actionCode)) {
            $this->dispatch('alert', type: 'error', message: __('Your plan does not allow F5TTS.'));
            return;
        }

        if ($this->isGenerating()) {
            $this->dispatch('alert', type: 'warning', message: __('A generation is already in progress.'));
            return;
        }

        $this->validate();

        $this->checkpoint = '';
        $this->device = 'auto';

        $text = trim((string) $this->text);
        $chars = $this->currentChars;
        $cost = $this->calculateCreditsCost($chars, $c);

        if ($cost <= 0) {
            $this->dispatch('alert', type: 'error', message: __('Pricing is not configured for this service. Please contact support.'));
            return;
        }

        try {
            $credits->charge((int) $c->id, $cost, 'ftts_charge', [
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

        $payload = [
            'mode' => 'f5',
            'speaker_key' => $this->speaker_id,
            'gen_text' => $text,
            'return_base64' => true,
            'checkpoint' => trim($this->checkpoint),
            'device' => trim($this->device),
            'use_ema' => (bool) $this->use_ema,
            'nfe_step' => (int) $this->nfe_step,
            'cfg_strength' => (float) $this->cfg_strength,
            'speed' => (float) $this->speed,
            'remove_silence' => (bool) $this->remove_silence,
        ];

        [$tool, $action] = $this->findToolAndAction();
        $jobId = (string) Str::uuid();
        $this->dismissedJobStatusFor = null;
        session()->forget('f5tts.dismissed_job_status_for');
        MlJob::create([
            'id'             => $jobId,
            'customer_id'    => $c->id,
            'tool_id'        => $tool->id,
            'tool_action_id' => $action->id,
            'job_kind'       => 'ftts',
            'status'         => 'queued',
            'provider'       => 'runpod',
            'input' => $payload,
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
            $endpointId = data_get($tool->meta, 'runpod_endpoint_id') ?: (config('runpod.endpoints.ftts') ?: env('RUNPOD_ENDPOINT_ID_FTTS'));
            if (!$endpointId) {
                throw new \RuntimeException(__('F5TTS endpoint ID is missing.'));
            }

            $timeout = (int) (data_get($tool->meta, 'runpod_timeout') ?: config('runpod.timeout', 60));

            $resp = $runpod->run($endpointId, $payload, $timeout);

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
            $this->syncWallet();

            // Persist SPA job state to JS
            $this->dispatch(
                'f5tts-job-started',
                jobId: $jobId,
                providerJobId: $rpId,
                status: 'running',
                progress: 20,
            );

            $this->dispatch('alert', type: 'success', message: __('RunPod job started.'));
        } catch (\Throwable $e) {
            $this->dispatch('header:refresh');
            $credits->refund((int) $c->id, $cost, 'ftts_refund', [
                'related_type' => 'ml_job',
                'related_id'   => $jobId,
                'tool_action'  => $actionCode,
                'reason'       => 'provider_start_failed',
            ]);

            $friendlyMessage = $this->friendlyF5FailureMessage($e->getMessage());

            MlJob::where('id', $jobId)->update([
                'status' => 'failed',
                'error' => ['message' => $friendlyMessage],
                'finished_at' => now(),
            ]);
            $this->currentStatus = 'failed';
            $this->jobFinished = true;
            $this->currentProgress = 100;
            $this->syncWallet();
            
            $this->dispatch('header:refresh');
            $this->dispatch('f5tts-job-state-clear');
            $this->dispatch('alert', type: 'error', message: $friendlyMessage);
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

            $this->dispatch(
                'f5tts-job-state-sync',
                jobId: $this->currentJobId,
                status: $this->currentStatus,
                progress: $this->currentProgress,
            );

            if (!empty($result['done'])) {
                $this->syncWallet();
                $this->dispatch('header:refresh');
                $this->dispatch('customerStorageUpdated');
                $this->dispatch('f5tts-renders-refresh');
                $this->dispatch('f5tts-job-completed');
                $this->dispatch('f5tts-job-state-clear');
                $this->dispatch('alert', type: 'success', message: __('Done'));
            }

            if (!empty($result['failed'])) {
                $this->dispatch('header:refresh');
                $this->dispatch('f5tts-job-state-clear');
                $this->dispatch('alert', type: 'error', message: $this->friendlyF5FailureMessage((string) ($result['message'] ?: __('Generation failed. Please retry.'))));
            }
        } catch (\Throwable $e) {
            Log::warning('RUNPOD_TTS_STATUS_FAIL', [
                'job_id' => $this->currentJobId,
                'err' => $e->getMessage(),
            ]);

            MlJob::query()->where('id', $this->currentJobId)->update([
                'status' => 'failed',
                'error' => ['message' => $this->friendlyF5FailureMessage($e->getMessage())],
                'finished_at' => now(),
            ]);

            $this->currentStatus = 'failed';
            $this->jobFinished = true;
            $this->currentProgress = 100;

            $this->dispatch('header:refresh');
            $this->dispatch('f5tts-job-state-clear');
            $this->dispatch('alert', type: 'error', message: $this->friendlyF5FailureMessage($e->getMessage()));
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
        $this->resetF5Settings();

        $this->syncSpeakerPickerSelection();
        $this->syncCostPreview();

        $this->dispatch('f5tts-form-state-clear');
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
            $this->dispatch('f5tts-job-state-clear');
            $this->dispatch('f5tts-form-state-clear');
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
        $this->dispatch('f5tts-job-state-clear');
        $this->dispatch('f5tts-form-state-clear');
        $this->dispatch('alert', type: 'warning', message: __('Current job eliminated. Credits were not refunded.'));
    }
    
    public function render()
    {
        return view('app.pages.f5tts.⚡app-f5tts');
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

    <x-slot:title>{{ __('F5TTS') }} | {{ __('MET KURD') }}</x-slot:title>

    <div id="f5tts-page-root">
    {{-- Poll only when a job is actively running --}}
    @if($currentJobId && !$jobFinished)
        <div wire:poll.visible.8000ms="pollJob"></div>
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
                            <div class="fw-semibold">{{ __('Delta Job Status') }}</div>
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
                                <strong>{{ __('MK-DELTA (MET KURDISH TEXT-TO-SPEECH)') }}</strong>
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
                                        id="f5ttsTipsButton"
                                        class="btn btn-outline-info mb-4"
                                        data-bs-toggle="modal"
                                        data-bs-target="#f5ttsTipsModal"
                                    >
                                        {{ __('Tips') }}
                                </button>
                                <br>
                                <label class="form-labe">{{ __('Text') }}</label>

                                <textarea
                                    class="form-control"
                                    rows="6"
                                    wire:model.live.debounce.900ms="text"
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
                                        wire:click="postF5tts"
                                        wire:loading.attr="disabled"
                                        wire:target="postF5tts"
                                        @disabled(!$this->canGenerate)
                                        type="button"
                                        id="btn-f5tts-generate"
                                    >
                                        <span wire:loading.remove wire:target="postF5tts">
                                            {{ $this->canGenerate ? __('Generate') : ($this->generateBlockedReason ?? __('Generate')) }}
                                        </span>
                                        <span wire:loading wire:target="postF5tts">
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
                                        class="xtts-speaker-picker"
                                        x-data="f5ttsSpeakerPicker(@js($speakerPicker))"
                                        x-init="init()"
                                        x-on:f5tts-speaker-preview-state.window="syncPreviewState($event.detail)"
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
                                                                                                <div class="fw-semibold text-light text-break" x-text="speaker.display_name"></div>
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

                                <div class="col-md-12">
                                    <div class="row g-3 mt-1">
                                        @foreach($this->sliders as $s)
                                            <div
                                                class="col-md-4"
                                                wire:key="slider-{{ $s['key'] }}-{{ md5((string) $s['val']) }}"
                                                x-data="{
                                                    key: '{{ $s['key'] }}',
                                                    val: @js($s['val']),
                                                    min: {{ $s['min'] }},
                                                    max: {{ $s['max'] }},
                                                    stepValue: {{ $s['step'] }},
                                                    debounceTimer: null,
                                                    updateLivewire(v) {
                                                        clearTimeout(this.debounceTimer);
                                                        this.debounceTimer = setTimeout(() => {
                                                            $wire.set(this.key, this.stepValue < 1 ? parseFloat(v) : parseInt(v));
                                                        }, 180);
                                                    },
                                                    get displayVal() {
                                                        return parseFloat(this.val).toFixed(this.stepValue < 1 ? 2 : 0);
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
                                                        :step="stepValue"
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
                                </div>

                                <div class="col-md-12">
                                    <div class="d-flex flex-wrap gap-3">
                                        <div class="form-check form-switch mt-2">
                                            <input class="form-check-input" type="checkbox" id="useEmaSwitchF5TTS" wire:model.change="use_ema">
                                            <label class="form-check-label" for="useEmaSwitchF5TTS">{{ __('Use EMA') }}</label>
                                        </div>

                                        <div class="form-check form-switch mt-2">
                                            <input class="form-check-input" type="checkbox" id="removeSilenceSwitchF5TTS" wire:model.change="remove_silence">
                                            <label class="form-check-label" for="removeSilenceSwitchF5TTS">{{ __('Remove silence') }}</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <livewire:partials.xtts-renders-panel tool-code="ftts" event-prefix="f5tts" stream-route="app.renders.f5tts.stream" download-route="app.renders.f5tts.download" dom-prefix="f5tts" page-name="f5ttsRendersPage" model-label="MK-F5TTS" />
    </div>
    <div class="modal fade" id="f5ttsTipsModal" tabindex="-1" aria-labelledby="f5ttsTipsModalLabel" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 bg-transparent">
                <h5 id="f5ttsTipsModalLabel" class="visually-hidden">{{ __('Tips') }}</h5>
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
    if (!window.__F5TTS_WAVE__) window.__F5TTS_WAVE__ = {};
    const S = window.__F5TTS_WAVE__;

    S.previewWS     = S.previewWS     || new Map(); // jobId Ã¢â€ â€™ WaveSurfer
    S.previewMeta   = S.previewMeta   || new Map(); // jobId Ã¢â€ â€™ { url, blobUrl }
    S.previewInit   = S.previewInit   || new Set(); // jobIds already preloaded
    S.pendingFetch  = S.pendingFetch  || new Map(); // url Ã¢â€ â€™ Promise<Blob>
    S.eventsBound   = S.eventsBound   || false;
    S.commitHooked  = S.commitHooked  || false;
    S.formWatchBoot = S.formWatchBoot || false;
    S.livewireOffs = S.livewireOffs || [];
    S.formWatchStops = S.formWatchStops || [];
    S.formWatchComponentId = S.formWatchComponentId || null;
    S.formSaveTimer = S.formSaveTimer || null;
    S.bootTimer = S.bootTimer || null;
    S.pageLifecycleBound = S.pageLifecycleBound || false;
    S.speakerPlayer = S.speakerPlayer || { audio: null, code: null, status: 'idle', urls: [], index: 0 };
    S.speakerErrors = S.speakerErrors || new Map();
    S.speakerPreviewToken = S.speakerPreviewToken || 0;

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Cache config Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    const CACHE_NAME    = 'f5tts-audio-v1';
    const CACHE_MAX     = 30;
    const PRELOAD_LIMIT = 10;

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ SPA / navigation persistence via localStorage Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    const SPA_KEY  = 'f5tts_spa_job';
    const FORM_KEY = 'f5tts_form_state_v1';

    function safeNumber(value, fallback, min = null, max = null) {
        const n = Number(value);
        let normalized = Number.isFinite(n) ? n : fallback;

        if (min !== null) {
            normalized = Math.max(Number(min), normalized);
        }

        if (max !== null) {
            normalized = Math.min(Number(max), normalized);
        }

        return normalized;
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

        const root = document.getElementById('f5tts-page-root');
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
        window.dispatchEvent(new CustomEvent('f5tts-speaker-preview-state', {
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

    window.f5ttsToggleSpeakerPreview = function (code, url) {
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

    window.f5ttsSpeakerPreviewSnapshot = speakerPreviewSnapshot;
    window.f5ttsSpeakerPicker = function (config = {}) {
        return {
            selected: String(config.selected || ''),
            speakers: config.groups || { female: [], male: [] },
            groupOrder: Array.isArray(config.group_order) ? config.group_order : [],
            messages: config.messages || {},
            speakerIndex: Object.create(null),
            avatarErrors: Object.create(null),
            previewState: speakerPreviewSnapshot(),

            init() {
                this.previewState = window.f5ttsSpeakerPreviewSnapshot();
                this.buildSpeakerIndex();
                const livewireSelected = this.readLivewireSelected();
                this.selected = String(
                    livewireSelected
                    || this.selected
                    || this.$refs.speakerSelect?.value
                    || this.firstSpeakerCode()
                    || ''
                );
                this.ensureSelectedSpeaker();
                this.syncNativeSelect(this.selected, false);

                if (String(livewireSelected || '') !== String(this.selected || '') && this.selected) {
                    this.pushSelectedToLivewire(this.selected);
                }
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

            firstSpeakerCode() {
                for (const group of this.groupOrder) {
                    const speakers = this.groupSpeakers(group.key);
                    if (Array.isArray(speakers) && speakers.length > 0) {
                        return String(speakers[0]?.code || '');
                    }
                }

                const firstKey = Object.keys(this.speakerIndex || {})[0] || '';
                return String(firstKey || '');
            },

            ensureSelectedSpeaker() {
                const current = String(this.selected || '');

                if (current !== '' && this.speakerFor(current)) {
                    return;
                }

                this.selected = this.firstSpeakerCode();
            },

            readLivewireSelected() {
                try {
                    if (this.$wire && typeof this.$wire.get === 'function') {
                        return String(this.$wire.get('speaker_id') || '');
                    }
                } catch (_) {}

                return '';
            },

            pushSelectedToLivewire(code) {
                const value = String(code || '');

                try {
                    if (this.$wire && typeof this.$wire.set === 'function') {
                        this.$wire.set('speaker_id', value);
                        return true;
                    }
                } catch (_) {}

                return false;
            },

            syncNativeSelect(code, shouldDispatch = true) {
                const value = String(code || '');
                const select = this.$refs.speakerSelect;

                if (!select) {
                    return;
                }

                select.value = value;

                if (shouldDispatch) {
                    if (!this.pushSelectedToLivewire(value)) {
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
            },

            syncSelectedFromNative(value) {
                this.selected = String(value || this.firstSpeakerCode() || '');
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
                window.f5ttsToggleSpeakerPreview(voiceCode, url);
            },

            syncPreviewState(detail) {
                this.previewState = detail || window.f5ttsSpeakerPreviewSnapshot();
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
        const existingMeta = S.previewMeta.get(jobId) || {};
        S.previewMeta.set(jobId, { ...existingMeta, url, blobUrl });

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

        const wave = document.getElementById('f5tts-wave-' + jobId);
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

        const existingWave = document.getElementById('f5tts-wave-' + jobId);
        const existingMeta = S.previewMeta.get(jobId);

        if (S.previewWS.has(jobId)) {
            const sameContainer = existingMeta?.waveEl && existingWave && existingMeta.waveEl === existingWave && existingMeta.waveEl.isConnected;
            const sameUrl = String(existingMeta?.url || '') === url;

            if (sameContainer && sameUrl) {
                return S.previewWS.get(jobId);
            }

            destroyPreview(jobId);
        }

        const ph = document.getElementById('f5tts-ph-' + jobId);
        const wave = existingWave || document.getElementById('f5tts-wave-' + jobId);
        const time = document.getElementById('f5tts-time-' + jobId);

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
            console.error('[F5TTS] WaveSurfer error', jobId, e);
        });

        (async () => {
            try {
                const blobUrl = await getBlobUrl(jobId, url);
                const cachedMeta = S.previewMeta.get(jobId) || {};
                S.previewMeta.set(jobId, { ...cachedMeta, url, waveEl: wave });
                ws.load(blobUrl);
            } catch (e) {
                console.warn('[F5TTS] Falling back to direct URL', jobId, e);
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
        return Array.from(document.querySelectorAll('.btn-f5tts-preview[data-job][data-url]'))
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
            const wave = document.getElementById('f5tts-wave-' + jobId);
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

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Button binding Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    function bindPreviewButtons() {
        document.querySelectorAll('.btn-f5tts-preview[data-job][data-url]').forEach(btn => {
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

        document.querySelectorAll('.btn-f5tts-stop[data-job]').forEach(btn => {
            if (btn.dataset.bound === '1') return;
            btn.dataset.bound = '1';

            btn.addEventListener('click', () => {
                stopWS(S.previewWS.get(btn.getAttribute('data-job')));
            });
        });
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Preload + render waveforms eagerly Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
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
                console.error('[F5TTS] Preload failed', jobId, e);
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

        const btn = firstCard.querySelector('.btn-f5tts-preview[data-job][data-url]');
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
            console.warn('[F5TTS] SPA restore failed', e);
        }
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Form persistence helpers Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    function formSave() {
        try {
            const lw = getLivewireComponent();
            if (!lw) return;

            const state = {
                text: lw.get('text') ?? '',
                speaker_id: lw.get('speaker_id') ?? '',
                use_ema: !!lw.get('use_ema'),
                nfe_step: parseInt(safeNumber(lw.get('nfe_step'), 32, 1, 128), 10),
                cfg_strength: safeNumber(lw.get('cfg_strength'), 2.0, 0, 10),
                speed: safeNumber(lw.get('speed'), 1.0, 0.1, 2),
                remove_silence: !!lw.get('remove_silence'),
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
            lw.set('use_ema', !!saved.use_ema);
            lw.set('nfe_step', parseInt(safeNumber(saved.nfe_step, 32, 1, 128), 10));
            lw.set('cfg_strength', safeNumber(saved.cfg_strength, 2.0, 0, 10));
            lw.set('speed', safeNumber(saved.speed, 1.0, 0.1, 2));
            lw.set('remove_silence', !!saved.remove_silence);
        } catch (e) {
            console.warn('[F5TTS] Form restore failed', e);
        }
    }

    function clearFormWatchers() {
        if (S.formSaveTimer) {
            clearTimeout(S.formSaveTimer);
            S.formSaveTimer = null;
        }

        (S.formWatchStops || []).forEach((stop) => {
            try {
                if (typeof stop === 'function') stop();
            } catch (_) {}
        });

        S.formWatchStops = [];
        S.formWatchBoot = false;
        S.formWatchComponentId = null;
    }

    function watchAndPersistForm() {
        const lw = getLivewireComponent();
        if (!lw || typeof lw.$watch !== 'function') return;

        const componentId = lw.id ?? lw.__instance?.id ?? null;
        if (S.formWatchBoot && S.formWatchComponentId === componentId) return;

        clearFormWatchers();

        S.formWatchBoot = true;
        S.formWatchComponentId = componentId;

        const debouncedSave = () => {
            clearTimeout(S.formSaveTimer);
            S.formSaveTimer = setTimeout(() => formSave(), 250);
        };

        [
            'text',
            'speaker_id',
            'use_ema',
            'nfe_step',
            'cfg_strength',
            'speed',
            'remove_silence',
        ].forEach((field) => {
            try {
                const stop = lw.$watch(field, debouncedSave);
                if (typeof stop === 'function') {
                    S.formWatchStops.push(stop);
                }
            } catch (_) {}
        });
    }

    function normalizeLivewirePayload(payload) {
        if (Array.isArray(payload)) {
            return payload[0] ?? {};
        }

        if (payload && typeof payload === 'object' && payload.detail && typeof payload.detail === 'object') {
            return payload.detail;
        }

        return payload && typeof payload === 'object' ? payload : {};
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Livewire event listeners Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    function clearLivewireEvents() {
        (S.livewireOffs || []).forEach((off) => {
            try {
                if (typeof off === 'function') off();
            } catch (_) {}
        });

        S.livewireOffs = [];
        S.eventsBound = false;
        S.commitHooked = false;
    }

    function registerLivewireEvents() {
        if (!window.Livewire || S.eventsBound) return;
        S.eventsBound = true;

        const on = (eventName, handler) => {
            try {
                const off = Livewire.on(eventName, handler);
                if (typeof off === 'function') {
                    S.livewireOffs.push(off);
                }
            } catch (_) {}
        };

        on('f5tts-job-started', (data) => {
            spaSave(normalizeLivewirePayload(data));
            formSave();
        });

        on('f5tts-job-state-sync', (data) => {
            data = normalizeLivewirePayload(data);
            const saved = spaLoad();
            if (saved && saved.jobId === data.jobId) {
                spaSave({ ...saved, ...data });
            }
        });

        on('f5tts-job-state-clear', () => {
            spaClear();
        });

        on('f5tts-form-state-clear', () => {
            formClear();
        });

        on('f5tts-job-completed', () => {
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

            const off = Livewire.hook('commit', ({ succeed }) => {
                succeed(() => {
                    requestAnimationFrame(() => {
                        bindPreviewButtons();
                        reconcilePreviewInstances();
                        preloadAndRenderRecentAudio().catch(() => {});
                        formSave();
                    });
                });
            });

            if (typeof off === 'function') {
                S.livewireOffs.push(off);
            }
        }
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Page boot Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    function bootF5ttsPage() {
        if (S.bootTimer) {
            clearTimeout(S.bootTimer);
        }

        const runBoot = () => {
            S.bootTimer = null;
            const root = document.getElementById('f5tts-page-root');
            if (!root) return;

            registerLivewireEvents();
            bindPreviewButtons();
            reconcilePreviewInstances();
            preloadAndRenderRecentAudio().catch(() => {});
            spaRestoreIfNeeded();
            formRestoreIfNeeded();
            watchAndPersistForm();

        };

        S.bootTimer = setTimeout(runBoot, 0);
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Cleanup on navigation Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    function teardownF5ttsPage() {
        if (S.bootTimer) {
            clearTimeout(S.bootTimer);
            S.bootTimer = null;
        }

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

        clearFormWatchers();
        clearLivewireEvents();
    }

    // Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ Initialise Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
    if (!S.pageLifecycleBound) {
        document.addEventListener('livewire:initialized', bootF5ttsPage);
        document.addEventListener('livewire:navigated', bootF5ttsPage);
        document.addEventListener('livewire:navigating', teardownF5ttsPage);

        window.addEventListener('beforeunload', () => {
            formSave();
            stopSpeakerPreview({ notify: false });
            clearFormWatchers();
            clearLivewireEvents();

            S.previewWS.forEach(ws => {
                try { ws.destroy(); } catch (_) {}
            });
            S.previewWS.clear();
        });

        S.pageLifecycleBound = true;
    }
})();
</script>
<script>
if (!window.__F5TTS_FORM_STATE_CLEAR_BOUND__) {
    window.__F5TTS_FORM_STATE_CLEAR_BOUND__ = true;

    window.addEventListener('f5tts-form-state-clear', () => {
        try {
            localStorage.removeItem('f5tts_form_state_v1');
        } catch (_) {}
    });
}
</script>
@endpush
