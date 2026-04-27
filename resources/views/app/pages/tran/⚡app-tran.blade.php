<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

use App\Models\Tool;
use App\Models\ToolAction;
use App\Models\MlJob;
use App\Support\AppToolCatalog;
use App\Services\Providers\RunPodProvider;
use App\Services\Billing\CreditService;
use App\Services\Storage\CustomerOutputStorage;
use App\Services\Translation\TranJobSyncService;

new
#[Layout('app::layouts.app')]
class extends Component
{
    use WithPagination;
    use \App\Support\Plans\ResolvesConcurrentJobLimit;

    protected $paginationTheme = 'bootstrap';

    protected string $toolCode = 'tran';
    protected string $fullActionCode = 'tran.standard';

    public ?string $currentJobId = null;
    public ?string $providerJobId = null;
    public ?string $currentStatus = null;
    public bool $jobFinished = false;
    public bool $showJobStatus = false;
    public int $currentProgress = 0;
    public ?string $dismissedJobStatusFor = null;

    public string $text = '';
    public string $sourceLang = 'ku';
    public string $targetLang = 'en';
    public int $maxNewTokens = 256;
    public int $chunkChars = 1200;

    public string $translatedText = '';
    public ?string $latestFinishedJobId = null;

    public int $walletBalance = 0;
    public int $creditsCost = 0;
    public int $maxPerSubmit = 2400;

    public array $primaryLanguageCodes = ['ku', 'en', 'ar'];

    public array $languageCatalog = [
        'af' => 'Afrikaans', 'am' => 'Amharic', 'ar' => 'Arabic', 'as' => 'Assamese',
        'be' => 'Belarusian', 'bg' => 'Bulgarian', 'bn' => 'Bengali', 'ca' => 'Catalan',
        'cs' => 'Czech', 'da' => 'Danish', 'de' => 'German', 'el' => 'Greek',
        'en' => 'English', 'es' => 'Spanish', 'et' => 'Estonian', 'eu' => 'Basque',
        'fa' => 'Persian', 'fi' => 'Finnish', 'fr' => 'French', 'gl' => 'Galician',
        'gu' => 'Gujarati', 'ha' => 'Hausa', 'he' => 'Hebrew', 'hi' => 'Hindi',
        'hr' => 'Croatian', 'hu' => 'Hungarian', 'id' => 'Indonesian', 'ig' => 'Igbo',
        'is' => 'Icelandic', 'it' => 'Italian', 'ja' => 'Japanese', 'ka' => 'Georgian',
        'kk' => 'Kazakh', 'km' => 'Khmer', 'kn' => 'Kannada', 'ko' => 'Korean',
        'ku' => 'Kurdish', 'lo' => 'Lao', 'lt' => 'Lithuanian', 'lv' => 'Latvian',
        'ml' => 'Malayalam', 'mr' => 'Marathi', 'ms' => 'Malay', 'my' => 'Burmese',
        'nb' => 'Norwegian (Bokmal)', 'ne' => 'Nepali', 'nl' => 'Dutch', 'or' => 'Odia',
        'pa' => 'Punjabi', 'pl' => 'Polish', 'pt' => 'Portuguese', 'ro' => 'Romanian',
        'ru' => 'Russian', 'si' => 'Sinhala', 'sk' => 'Slovak', 'sl' => 'Slovenian',
        'sr' => 'Serbian', 'sv' => 'Swedish', 'sw' => 'Swahili', 'ta' => 'Tamil',
        'te' => 'Telugu', 'th' => 'Thai', 'tr' => 'Turkish', 'uk' => 'Ukrainian',
        'ur' => 'Urdu', 'vi' => 'Vietnamese', 'yo' => 'Yoruba', 'zh' => 'Chinese',
        'zu' => 'Zulu',
    ];

    public function mount(): void
    {
        $this->syncWallet();
        $this->syncCostPreview();
        $this->dismissedJobStatusFor = session('tran.dismissed_job_status_for');
        $this->hydrateCurrentJobFromDb();
        $this->hydrateLatestFinishedResult();
    }

    #[On('header:refresh')]
    #[On('customerPlanUpdated')]
    #[On('customerStorageUpdated')]
    #[On('tran-renders-refresh')]
    public function refreshUi(): void
    {
        $this->syncWallet();
        $this->syncCostPreview();
        $this->hydrateCurrentJobFromDb();
        $this->hydrateLatestFinishedResult();
    }

    public function updatedText(): void
    {
        $this->syncCostPreview();
    }

    public function updatedSourceLang(): void
    {
        $this->syncCostPreview();
    }

    public function updatedTargetLang(): void
    {
        $this->syncCostPreview();
    }

    #[Computed]
    public function currentChars(): int
    {
        return mb_strlen((string) $this->text);
    }

    #[Computed]
    public function sliders(): array
    {
        return [
            [
                'key' => 'maxNewTokens',
                'label' => __('Max New Tokens'),
                'helper' => __('Upper limit for the translated output length.'),
                'min' => 64,
                'max' => 2048,
                'step' => 1,
                'val' => $this->maxNewTokens,
            ],
            [
                'key' => 'chunkChars',
                'label' => __('Chunk Characters'),
                'helper' => __('Split long text into safer translation-sized chunks.'),
                'min' => 200,
                'max' => 5000,
                'step' => 1,
                'val' => $this->chunkChars,
            ],
        ];
    }

    #[Computed]
    public function canTranslate(): bool
    {
        return $this->translateBlockedReason === null;
    }

    #[Computed]
    public function translateBlockedReason(): ?string
    {
        if ($this->isTranslating()) {
            return __('A translation is already in progress.');
        }

        if ($this->currentActiveJobsCount() >= $this->allowedConcurrentJobs()) {
            return __('You reached your concurrent job limit for the current plan.');
        }

        if (trim($this->text) === '') {
            return __('Please enter text to translate.');
        }

        if ($this->sourceLang === $this->targetLang) {
            return __('Choose different source and target languages.');
        }

        if ($this->currentChars > $this->maxPerSubmit) {
            return __('The text exceeds the current character limit per request.');
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
    public function recentTranslations()
    {
        $customerId = auth('app')->id();
        $toolId = app(AppToolCatalog::class)->toolId($this->toolCode);

        if (!$customerId || !$toolId) {
            return MlJob::query()->whereRaw('1=0')->paginate(5);
        }

        return MlJob::query()
            ->where('customer_id', $customerId)
            ->where('tool_id', $toolId)
            ->whereIn('status', ['done', 'delete_failed'])
            ->orderByDesc('finished_at')
            ->paginate(5);
    }

    protected function syncWallet(): void
    {
        $customer = auth('app')->user();
        $wallet = $customer?->wallet()->first();

        $this->walletBalance = (int) ($wallet?->subscription_balance_credits ?? 0)
            + (int) ($wallet?->addon_balance_credits ?? 0);

        $this->maxPerSubmit = 2400;

        if (method_exists($customer, 'entitlementLimitFor')) {
            $this->maxPerSubmit = (int) ($customer->entitlementLimitFor($this->fullActionCode, 'max_chars_per_submit') ?? 2400);
        }
    }

    protected function syncCostPreview(): void
    {
        $customer = auth('app')->user();

        if (!$customer || trim($this->text) === '' || $this->currentChars <= 0) {
            $this->creditsCost = 0;
            return;
        }

        if (method_exists($customer, 'priceCreditsFor')) {
            $this->creditsCost = (int) $customer->priceCreditsFor($this->fullActionCode, [
                'chars' => $this->currentChars,
                'metric_code' => 'character',
                'source_lang' => $this->sourceLang,
                'target_lang' => $this->targetLang,
            ]);
            return;
        }

        $this->creditsCost = (int) ceil($this->currentChars * 1.0);
    }

    protected function currentActiveJobsCount(): int
    {
        $customerId = auth('app')->id();
        $toolId = app(AppToolCatalog::class)->toolId($this->toolCode);

        if (!$customerId || !$toolId) {
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
        $supported = implode(',', array_keys($this->languageCatalog));

        return [
            'text' => ['required', 'string', 'min:1', 'max:' . $this->maxPerSubmit],
            'sourceLang' => 'required|string|in:' . $supported,
            'targetLang' => 'required|string|in:' . $supported . '|different:sourceLang',
            'maxNewTokens' => 'required|integer|min:64|max:2048',
            'chunkChars' => 'required|integer|min:200|max:5000',
        ];
    }

    protected function isTranslating(): bool
    {
        return (bool) $this->currentJobId
            && !$this->jobFinished
            && in_array($this->currentStatus, ['queued', 'running', 'saving'], true);
    }

    protected function findToolAndAction(): array
    {
        $tool = Tool::query()->where('code', $this->toolCode)->first();
        $action = ToolAction::query()->where('full_code', $this->fullActionCode)->first();

        if (!$tool || !$action) {
            throw new \RuntimeException(__('Tool or ToolAction is missing for :tool.', ['tool' => "{$this->toolCode} / {$this->fullActionCode}"]));
        }

        return [$tool, $action];
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
            ->where(function ($query) {
                $query->whereIn('status', ['queued', 'running', 'saving'])
                    ->orWhere(function ($finished) {
                        $finished->whereIn('status', ['done', 'failed'])
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

        $this->currentJobId = (string) $job->id;
        $this->providerJobId = (string) ($job->provider_job_id ?? '');
        $this->currentStatus = (string) $job->status;
        $this->jobFinished = in_array((string) $job->status, ['done', 'failed', 'deleted'], true);
        $this->currentProgress = match ((string) $job->status) {
            'queued' => 10,
            'running' => 45,
            'saving' => 90,
            'done', 'failed', 'deleted' => 100,
            default => 0,
        };

        if ((string) $job->status === 'done') {
            $this->translatedText = (string) data_get($job->output, 'text', '');
            $this->latestFinishedJobId = (string) $job->id;
        }

        if (!$this->jobFinished) {
            $this->showJobStatus = true;
            return;
        }

        $finishedAt = $job->finished_at;
        $this->showJobStatus = $this->dismissedJobStatusFor !== (string) $job->id
            && $finishedAt
            && $finishedAt->gte(now()->subSeconds(3));
    }

    protected function hydrateLatestFinishedResult(): void
    {
        $customerId = auth('app')->id();
        $toolId = app(AppToolCatalog::class)->toolId($this->toolCode);

        if (!$customerId || !$toolId) {
            return;
        }

        $job = MlJob::query()
            ->where('customer_id', $customerId)
            ->where('tool_id', $toolId)
            ->where('status', 'done')
            ->orderByDesc('finished_at')
            ->first();

        if ($job) {
            $this->latestFinishedJobId = (string) $job->id;
            if ($this->translatedText === '') {
                $this->translatedText = (string) data_get($job->output, 'text', '');
            }
        }
    }

    public function postTran(
        RunPodProvider $runpod,
        CreditService $credits,
        CustomerOutputStorage $storage
    ): void {
        $this->showJobStatus = true;
        $this->hydrateCurrentJobFromDb();

        $customer = auth('app')->user();
        if (!$customer) {
            $this->dispatch('alert', type: 'error', message: __('You must be logged in.'));
            return;
        }

        if ($this->currentActiveJobsCount() >= $this->allowedConcurrentJobs()) {
            $this->dispatch('alert', type: 'warning', message: __('You reached your concurrent job limit for the current plan.'));
            return;
        }

        if (method_exists($customer, 'isAllowed') && !$customer->isAllowed($this->fullActionCode)) {
            $this->dispatch('alert', type: 'error', message: __('Your plan does not allow MET Translation.'));
            return;
        }

        if ($this->isTranslating()) {
            $this->dispatch('alert', type: 'warning', message: __('A translation is already in progress.'));
            return;
        }

        $this->validate();

        $text = trim((string) $this->text);
        $chars = $this->currentChars;
        $cost = method_exists($customer, 'priceCreditsFor')
            ? (int) $customer->priceCreditsFor($this->fullActionCode, [
                'chars' => $chars,
                'metric_code' => 'character',
                'source_lang' => $this->sourceLang,
                'target_lang' => $this->targetLang,
            ])
            : (int) ceil($chars * 1.0);

        if ($cost <= 0) {
            $this->dispatch('alert', type: 'error', message: __('Pricing is not configured.'));
            return;
        }

        $jobId = (string) Str::uuid();
        $savedSource = null;
        $charged = false;

        try {
            $credits->charge((int) $customer->id, $cost, 'tran_charge', [
                'related_type' => 'ml_job',
                'related_id' => null,
                'tool_action' => $this->fullActionCode,
                'chars' => $chars,
                'source_lang' => $this->sourceLang,
                'target_lang' => $this->targetLang,
            ]);
            $charged = true;

            [$tool, $action] = $this->findToolAndAction();
            $this->dismissedJobStatusFor = null;
            session()->forget('tran.dismissed_job_status_for');

            MlJob::create([
                'id' => $jobId,
                'customer_id' => (int) $customer->id,
                'tool_id' => (int) $tool->id,
                'tool_action_id' => (int) $action->id,
                'job_kind' => 'tran',
                'status' => 'queued',
                'provider' => 'runpod',
                'provider_job_id' => null,
                'input' => [
                    'text' => $text,
                    'source_lang' => $this->sourceLang,
                    'target_lang' => $this->targetLang,
                    'max_new_tokens' => (int) $this->maxNewTokens,
                    'chunk_chars' => (int) $this->chunkChars,
                    'source_char_count' => $chars,
                ],
                'output' => null,
                'error' => null,
                'credits_charged' => $cost,
                'started_at' => now(),
                'storage_in_bytes' => 0,
                'storage_out_bytes' => 0,
            ]);

            $customerFresh = $customer->loadMissing('profile');
            $folder = $this->currentFolderForCustomer($customerFresh);
            $sourceKey = "renders/{$folder}/tran/{$jobId}/source.txt";

            $savedSource = $storage->saveTextToS3(
                (int) $customer->id,
                $sourceKey,
                $text,
                [
                    'job_id' => $jobId,
                    'tool' => 'tran',
                    'purpose' => 'source_text',
                    'mime' => 'text/plain; charset=UTF-8',
                ]
            );

            MlJob::query()->where('id', $jobId)->update([
                'input' => array_merge((array) (MlJob::find($jobId)?->input ?? []), [
                    'source_disk' => $savedSource['disk'],
                    'source_path' => $savedSource['path'],
                    'source_bytes' => $savedSource['bytes'],
                ]),
                'storage_in_bytes' => (int) $savedSource['bytes'],
            ]);

            $endpointId = (string) (
                data_get($tool->meta, 'runpod_endpoint_id')
                ?: config('runpod.endpoints.tran')
                ?: env('RUNPOD_ENDPOINT_ID_TRAN')
            );

            if ($endpointId === '') {
                throw new \RuntimeException(__('RUNPOD_ENDPOINT_ID_TRAN is missing.'));
            }

            $response = $runpod->run($endpointId, [
                'text' => $text,
                'source_lang' => $this->sourceLang,
                'target_lang' => $this->targetLang,
                'max_new_tokens' => (int) $this->maxNewTokens,
                'chunk_chars' => (int) $this->chunkChars,
            ], (int) (data_get($tool->meta, 'runpod_timeout') ?: config('runpod.timeout', 60)));

            $providerJobId = (string) data_get($response, 'id', '');
            if ($providerJobId === '') {
                throw new \RuntimeException(__('RunPod did not return a job ID.'));
            }

            MlJob::query()->where('id', $jobId)->update([
                'status' => 'running',
                'provider_job_id' => $providerJobId,
                'execution_scope' => 'customer',
                'lock_expires_at' => now()->addMinutes(60),
                'updated_at' => now(),
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
            $this->dispatch('tran-renders-refresh');
            $this->dispatch('alert', type: 'info', message: __('Translation job started.'));

            $this->syncWallet();
        } catch (\Throwable $e) {
            Log::warning('TRAN_START_FAIL', ['job_id' => $jobId, 'error' => $e->getMessage()]);

            try {
                if ($savedSource && !empty($savedSource['path'])) {
                    $storage->deleteFromS3AndUncount((int) $customer->id, (string) $savedSource['path'], (int) ($savedSource['bytes'] ?? 0));
                }
            } catch (\Throwable $cleanup) {
                Log::warning('TRAN_START_CLEANUP_FAIL', ['job_id' => $jobId, 'error' => $cleanup->getMessage()]);
            }

            if ($charged) {
                try {
                    $credits->refund((int) $customer->id, $cost, 'tran_refund', [
                        'related_type' => 'ml_job',
                        'related_id' => $jobId,
                        'tool_action' => $this->fullActionCode,
                        'reason' => 'provider_start_failed',
                    ]);
                } catch (\Throwable $refund) {
                    Log::warning('TRAN_REFUND_FAIL', ['job_id' => $jobId, 'error' => $refund->getMessage()]);
                }
            }

            MlJob::query()->where('id', $jobId)->update([
                'status' => 'failed',
                'error' => ['message' => $e->getMessage()],
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            $this->syncWallet();
            $this->dispatch('header:refresh');
            $this->dispatch('customerStorageUpdated');
            $this->dispatch('alert', type: 'error', message: __('Translation failed to start: :message', ['message' => $e->getMessage()]));
            $this->hydrateCurrentJobFromDb();
        }
    }

    public function pollJob(TranJobSyncService $sync): void
    {
        if (!$this->currentJobId || $this->jobFinished) {
            return;
        }

        $job = MlJob::query()->with('tool')->where('id', $this->currentJobId)->where('customer_id', auth('app')->id())->first();

        if (!$job || !$job->tool) {
            return;
        }

        try {
            $result = $sync->sync($job, $job->tool);
            $this->currentStatus = (string) ($result['status'] ?? $this->currentStatus);
            $this->currentProgress = (int) ($result['progress'] ?? $this->currentProgress);

            if (($result['done'] ?? false) === true) {
                $this->jobFinished = true;
                $this->translatedText = (string) ($result['text'] ?? '');
                $this->showJobStatus = true;
                $this->latestFinishedJobId = $this->currentJobId;
                $this->hydrateLatestFinishedResult();
                $this->dispatch('customerPlanUpdated');
                $this->dispatch('customerStorageUpdated');
                $this->dispatch('tran-renders-refresh');
                $this->dispatch('header:refresh');
                $this->dispatch('alert', type: 'success', message: __('Translation completed.'));
            }

            if (($result['failed'] ?? false) === true) {
                $this->jobFinished = true;
                $this->showJobStatus = true;
                $this->dispatch('header:refresh');
                $this->dispatch('alert', type: 'error', message: (string) ($result['message'] ?? __('Translation failed.')));
            }
        } catch (\Throwable $e) {
            Log::warning('TRAN_POLL_FAIL', ['job_id' => $this->currentJobId, 'error' => $e->getMessage()]);
        }
    }

    public function copyTranslation(): void
    {
        if (trim($this->translatedText) === '') {
            $this->dispatch('alert', type: 'warning', message: __('No translation to copy.'));
            return;
        }

        $this->dispatch('tran-copy-text', text: $this->translatedText);
        $this->dispatch('alert', type: 'success', message: __('Translation copied.'));
    }

    public function deleteTranslation(string $jobId, TranJobSyncService $sync): void
    {
        $job = MlJob::query()->where('id', $jobId)->where('customer_id', auth('app')->id())->firstOrFail();
        $sync->deleteFinishedTranslation($job);

        if ($this->latestFinishedJobId === $jobId) {
            $this->latestFinishedJobId = null;
            $this->translatedText = '';
        }

        $this->dispatch('customerStorageUpdated');
        $this->dispatch('header:refresh');
        $this->dispatch('tran-renders-refresh');
        $this->dispatch('alert', type: 'success', message: __('Translation deleted.'));
    }

    public function swapLanguages(): void
    {
        [$this->sourceLang, $this->targetLang] = [$this->targetLang, $this->sourceLang];
        [$this->text, $this->translatedText] = [$this->translatedText, $this->text];
        $this->latestFinishedJobId = null;
        $this->syncCostPreview();
    }

    public function setSourceLang(string $code): void
    {
        if (!array_key_exists($code, $this->languageCatalog)) return;
        if ($code === $this->targetLang) $this->targetLang = $this->sourceLang;
        $this->sourceLang = $code;
        $this->syncCostPreview();
    }

    public function setTargetLang(string $code): void
    {
        if (!array_key_exists($code, $this->languageCatalog)) return;
        if ($code === $this->sourceLang) $this->sourceLang = $this->targetLang;
        $this->targetLang = $code;
        $this->syncCostPreview();
    }

    public function resetForm(): void
    {
        $this->text = '';
        $this->sourceLang = 'ku';
        $this->targetLang = 'en';
        $this->maxNewTokens = 256;
        $this->chunkChars = 1200;
        $this->creditsCost = 0;
    }

    public function hideJobStatus(): void
    {
        if ($this->currentJobId) {
            $this->dismissedJobStatusFor = $this->currentJobId;
            session(['tran.dismissed_job_status_for' => $this->currentJobId]);
        }

        $this->showJobStatus = false;
        $this->currentJobId = null;
        $this->providerJobId = null;
        $this->currentStatus = null;
        $this->jobFinished = false;
        $this->currentProgress = 0;
    }

    public function langDirection(?string $code): string
    {
        return in_array((string) $code, ['ar', 'fa', 'he', 'ku', 'ur'], true) ? 'rtl' : 'ltr';
    }

    public function isPrimaryLanguageActive(?string $selectedCode, string $pillCode): bool
    {
        $selectedCode = strtolower(trim((string) $selectedCode));
        $pillCode = strtolower(trim($pillCode));

        return in_array($selectedCode, $this->primaryLanguageCodes, true)
            && $selectedCode === $pillCode;
    }

    public function languageBadge(?string $code): string
    {
        $code = strtolower(trim((string) $code));

        if ($code === '') {
            return __('Unknown');
        }

        $label = (string) ($this->languageCatalog[$code] ?? strtoupper($code));

        return strtoupper($code) . ' - ' . $label;
    }

    public function render()
    {
        return view('app.pages.tran.⚡app-tran');
    }
};
?>

<x-slot:title>{{ __('MET Translation') }} | {{ __('MET KURD') }}</x-slot:title>

@php
    $primaryLanguages = array_intersect_key($languageCatalog, array_flip($primaryLanguageCodes));
    $secondaryLanguages = array_diff_key($languageCatalog, $primaryLanguages);

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
@endphp

<div id="tran-page-root">
    @if($currentJobId && !$jobFinished)
        <div wire:poll.visible.7000ms="pollJob"></div>
    @endif

    <div class="row g-3">
        <div class="col-12">
            @if($showJobStatus && $currentJobId && $currentStatus)
                <div class="glass-load {{ $glassClass }} p-3">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                        <div>
                            <div class="fw-semibold">{{ __('Translation Job Status') }}</div>
                            <div class="small text-muted">{{ __('Job ID:') }} {{ $currentJobId ?: '-' }}</div>
                        </div>
                        <span class="badge text-bg-{{ $badge }} fs-6 px-3 py-2">{{ $status }}</span>
                    </div>

                    <div class="progress" role="progressbar" aria-valuenow="{{ $currentProgress }}" aria-valuemin="0" aria-valuemax="100" style="height:8px;">
                        <div class="progress-bar progress-bar-striped {{ !$jobFinished ? 'progress-bar-animated' : '' }} bg-{{ $badge }}" style="width: {{ $currentProgress }}%"></div>
                    </div>

                    <div class="d-flex align-items-center justify-content-between mt-2 small">
                        <span class="text-muted">{{ $currentProgress }}%</span>
                        @if($jobFinished)
                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="hideJobStatus">{{ __('Hide') }}</button>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        <div class="col-xl-8">
            <div class="turbo-border mb-3">
                <div class="turbo-inner">
                    <div class="card mb-0">
                        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3">
                            <div>
                                <strong>{{ __('MET Translation') }}</strong>
                                <div class="text-muted small">{{ __('Fast translation workspace for Kurdish, English, Arabic, and more.') }}</div>
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
                                    <div class="text-muted">{{ __('Chars') }}</div>
                                    <div class="fw-semibold">{{ number_format($this->currentChars) }}</div>
                                </div>
                                <div class="mini-stat">
                                    <div class="text-muted">{{ __('Max/Submit') }}</div>
                                    <div class="fw-semibold">{{ number_format($maxPerSubmit) }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="card-body">
                            <div class="tran-toolbar mb-3">
                                <div class="row g-3 align-items-end">
                                    <div class="col-lg-5">
                                        <label class="form-label">{{ __('Source Language') }}</label>
                                        <div class="tran-chip-row mb-2">
                                            @foreach($primaryLanguages as $code => $label)
                                                <button
                                                    type="button"
                                                    class="btn btn-sm tran-lang-chip {{ $this->isPrimaryLanguageActive($sourceLang, $code) ? 'is-active' : '' }}"
                                                    wire:click="setSourceLang('{{ $code }}')"
                                                >
                                                    <span>{{ strtoupper($code) }}</span>
                                                    <small>{{ $label }}</small>
                                                </button>
                                            @endforeach
                                        </div>
                                        <select class="form-select" wire:change="setSourceLang($event.target.value)">
                                            <optgroup label="{{ __('Primary Languages') }}">
                                                @foreach($primaryLanguages as $code => $label)
                                                    <option value="{{ $code }}" @selected($sourceLang === $code)>{{ $label }}</option>
                                                @endforeach
                                            </optgroup>
                                            <optgroup label="{{ __('All Languages') }}">
                                                @foreach($secondaryLanguages as $code => $label)
                                                    <option value="{{ $code }}" @selected($sourceLang === $code)>{{ $label }}</option>
                                                @endforeach
                                            </optgroup>
                                        </select>
                                    </div>

                                    <div class="col-lg-2">
                                        <div class="d-flex justify-content-center">
                                            <button type="button" class="btn btn-outline-secondary tran-swap-btn" wire:click="swapLanguages">
                                                <i class="ri-arrow-left-right-line me-1"></i>{{ __('Swap') }}
                                            </button>
                                        </div>
                                    </div>

                                    <div class="col-lg-5">
                                        <label class="form-label">{{ __('Target Language') }}</label>
                                        <div class="tran-chip-row mb-2">
                                            @foreach($primaryLanguages as $code => $label)
                                                <button
                                                    type="button"
                                                    class="btn btn-sm tran-lang-chip {{ $this->isPrimaryLanguageActive($targetLang, $code) ? 'is-active' : '' }}"
                                                    wire:click="setTargetLang('{{ $code }}')"
                                                >
                                                    <span>{{ strtoupper($code) }}</span>
                                                    <small>{{ $label }}</small>
                                                </button>
                                            @endforeach
                                        </div>
                                        <select class="form-select" wire:change="setTargetLang($event.target.value)">
                                            <optgroup label="{{ __('Primary Languages') }}">
                                                @foreach($primaryLanguages as $code => $label)
                                                    <option value="{{ $code }}" @selected($targetLang === $code)>{{ $label }}</option>
                                                @endforeach
                                            </optgroup>
                                            <optgroup label="{{ __('All Languages') }}">
                                                @foreach($secondaryLanguages as $code => $label)
                                                    <option value="{{ $code }}" @selected($targetLang === $code)>{{ $label }}</option>
                                                @endforeach
                                            </optgroup>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3">
                                <div class="col-lg-6">
                                    <div class="tran-pane h-100">
                                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                            <div>
                                                <div class="fw-semibold">{{ __('Source Text') }}</div>
                                                <div class="small text-muted">{{ __('Paste or write the text you want to translate.') }}</div>
                                            </div>
                                            <span class="badge tran-badge">{{ $this->languageBadge($sourceLang) }}</span>
                                        </div>

                                        <textarea class="form-control tran-textarea" rows="12" wire:model.live.debounce.700ms="text" dir="{{ $this->langDirection($sourceLang) }}" placeholder="{{ __('Write or paste text here') }}"></textarea>

                                        <div class="d-flex justify-content-between align-items-center mt-2 small flex-wrap gap-2">
                                            <div class="text-muted">{{ __('Characters: :count', ['count' => number_format($this->currentChars)]) }}</div>
                                            <button class="btn btn-sm btn-link p-0 text-decoration-none" wire:click="resetForm" type="button">{{ __('Clear') }}</button>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-lg-6">
                                    <div class="tran-pane tran-pane--result h-100">
                                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                            <div>
                                                <div class="fw-semibold">{{ __('Translated Text') }}</div>
                                                <div class="small text-muted">{{ __('Your latest translated output appears here.') }}</div>
                                            </div>
                                            <span class="badge tran-badge">{{ $this->languageBadge($targetLang) }}</span>
                                        </div>

                                        <div class="tran-output-shell">
                                            @if($translatedText !== '')
                                                <div class="tran-output-text" dir="{{ $this->langDirection($targetLang) }}">{{ $translatedText }}</div>
                                            @else
                                                <div class="tran-output-empty">
                                                    <div class="fw-semibold mb-1">{{ __('Ready for translation') }}</div>
                                                    <div class="small text-muted">{{ __('Generate a translation and the result will appear here.') }}</div>
                                                </div>
                                            @endif
                                        </div>

                                        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                                            <div class="small text-muted">
                                                @if($translatedText !== '')
                                                    {{ __('Characters: :count', ['count' => number_format(mb_strlen($translatedText))]) }}
                                                @else
                                                    {{ __('Target text will be stored in your render history.') }}
                                                @endif
                                            </div>

                                            <div class="d-flex gap-2 flex-wrap">
                                                <button class="btn btn-sm btn-outline-primary" wire:click="copyTranslation" type="button" @disabled(trim($translatedText) === '')>{{ __('Copy') }}</button>
                                                @if($latestFinishedJobId)
                                                    <a class="btn btn-sm btn-outline-success" href="{{ route('app.renders.tran.target', ['locale' => app()->getLocale(), 'jobId' => $latestFinishedJobId]) }}">{{ __('Download') }}</a>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mt-1">
                                @foreach($this->sliders as $s)
                                    <div
                                        class="col-md-6"
                                        wire:key="tran-slider-{{ $s['key'] }}-{{ md5((string) $s['val']) }}"
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
                                                    $wire.set(this.key, parseInt(v));
                                                }, 180);
                                            },
                                            get displayVal() {
                                                return parseInt(this.val);
                                            }
                                        }"
                                    >
                                        <div class="tran-slider-shell">
                                            <div class="d-flex justify-content-between align-items-start gap-3">
                                                <div>
                                                    <label class="form-label mb-1">{{ $s['label'] }}</label>
                                                    <div class="small text-muted">{{ $s['helper'] }}</div>
                                                </div>
                                                <span class="badge text-bg-light tran-slider-badge" x-text="displayVal"></span>
                                            </div>

                                            <div class="d-flex justify-content-between small text-muted tran-slider-scale">
                                                <span>{{ number_format($s['min']) }}</span>
                                                <span>{{ number_format($s['max']) }}</span>
                                            </div>

                                            <input
                                                type="range"
                                                class="form-range tran-range"
                                                :min="min"
                                                :max="max"
                                                :step="step"
                                                x-model="val"
                                                @input="updateLivewire($event.target.value)"
                                            />
                                        </div>

                                        @error($s['key'])
                                            <div class="text-danger small mt-1">{{ $message }}</div>
                                        @enderror
                                    </div>
                                @endforeach
                            </div>

                            @if($this->currentChars > 0 && $creditsCost > 0)
                                <div class="tran-cost-preview rounded-3 p-3 mt-3 small">
                                    {{ __('This translation will process about :chars character(s) from :source to :target.', [
                                        'chars' => number_format($this->currentChars),
                                        'source' => $languageCatalog[$sourceLang] ?? strtoupper($sourceLang),
                                        'target' => $languageCatalog[$targetLang] ?? strtoupper($targetLang),
                                    ]) }}
                                </div>
                            @endif

                            <div class="d-flex gap-2 mt-4 flex-wrap">
                                <button class="btn {{ $this->canTranslate ? 'btn-primary' : 'btn-danger' }}" wire:click="postTran" wire:loading.attr="disabled" wire:target="postTran" @disabled(!$this->canTranslate) type="button">
                                    <span wire:loading.remove wire:target="postTran">
                                        {{ $this->canTranslate ? __('Translate') : ($this->translateBlockedReason ?? __('Translate')) }}
                                    </span>
                                    <span wire:loading wire:target="postTran">
                                        <span class="spinner-border spinner-border-sm me-1"></span>{{ __('Starting...') }}
                                    </span>
                                </button>

                                <button class="btn btn-outline-secondary" wire:click="resetForm" type="button">{{ __('Reset') }}</button>

                                @if($walletBalance < $creditsCost && $creditsCost > 0)
                                    <span class="small text-danger align-self-center">{{ __('Not enough credits.') }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="turbo-border mb-3">
                <div class="turbo-inner">
                    <div class="card mb-0">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-3">
                            <div>
                                <strong>{{ __('Recent Translations') }}</strong>
                                <div class="small text-muted">{{ __('Your latest translation jobs and stored outputs.') }}</div>
                            </div>
                            <button class="btn btn-sm btn-outline-secondary" wire:click="$refresh" type="button">{{ __('Refresh') }}</button>
                        </div>

                        <div class="card-body">
                            @if($this->recentTranslations->count() === 0)
                                <div class="tran-output-empty">
                                    <div class="fw-semibold mb-1">{{ __('No translations yet') }}</div>
                                    <div class="small text-muted">{{ __('Submit a translation and your recent history will appear here.') }}</div>
                                </div>
                            @else
                                @foreach($this->recentTranslations as $job)
                                    @php
                                        $sourceText = (string) data_get($job->input, 'text', '');
                                        $targetText = (string) data_get($job->output, 'text', '');
                                        $sourceLangLabel = $languageCatalog[data_get($job->input, 'source_lang', '')] ?? strtoupper((string) data_get($job->input, 'source_lang', ''));
                                        $targetLangLabel = $languageCatalog[data_get($job->input, 'target_lang', '')] ?? strtoupper((string) data_get($job->input, 'target_lang', ''));
                                    @endphp
                                    <div class="render-card tran-history-item {{ $loop->first ? 'tran-history-item--latest' : '' }} mb-3 p-3 rounded-4 border">
                                        <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                                            <span class="badge tran-badge">{{ strtoupper((string) data_get($job->input, 'source_lang', '')) }} → {{ strtoupper((string) data_get($job->input, 'target_lang', '')) }}</span>
                                            @if($loop->first)
                                                <span class="badge text-bg-primary">{{ __('Latest') }}</span>
                                            @endif
                                        </div>

                                        <div class="small text-muted mb-2">
                                            {{ optional($job->finished_at ?? $job->created_at)->format('Y-m-d H:i') }}
                                            | {{ __('Credits: :count', ['count' => number_format((int) ($job->credits_charged ?? 0))]) }}
                                        </div>

                                        <div class="tran-history-label">{{ __('Source') }} · {{ $sourceLangLabel }}</div>
                                        <div class="tran-history-snippet" dir="{{ $this->langDirection((string) data_get($job->input, 'source_lang', '')) }}">{{ mb_strlen($sourceText) > 150 ? mb_substr($sourceText, 0, 150) . '...' : $sourceText }}</div>

                                        <div class="tran-history-label mt-3">{{ __('Translation') }} · {{ $targetLangLabel }}</div>
                                        <div class="tran-history-snippet tran-history-snippet--target" dir="{{ $this->langDirection((string) data_get($job->input, 'target_lang', '')) }}">{{ mb_strlen($targetText) > 180 ? mb_substr($targetText, 0, 180) . '...' : $targetText }}</div>

                                        <div class="d-flex gap-2 flex-wrap mt-3">
                                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('app.renders.tran.source', ['locale' => app()->getLocale(), 'jobId' => $job->id]) }}">{{ __('Source TXT') }}</a>
                                            <a class="btn btn-sm btn-outline-success" href="{{ route('app.renders.tran.target', ['locale' => app()->getLocale(), 'jobId' => $job->id]) }}">{{ __('Target TXT') }}</a>
                                            <button class="btn btn-sm btn-outline-danger" wire:click="deleteTranslation('{{ $job->id }}')" wire:loading.attr="disabled" wire:target="deleteTranslation('{{ $job->id }}')" type="button">{{ __('Delete') }}</button>
                                        </div>
                                    </div>
                                @endforeach
                            @endif

                            @if($this->recentTranslations->hasPages())
                                <div class="mt-3">{{ $this->recentTranslations->links(data: ['scrollTo' => false]) }}</div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('styles')
<style>
    .tran-toolbar,.tran-pane,.tran-output-shell,.tran-history-item{border:1px solid rgba(255,255,255,.06)}
    .tran-toolbar{padding:1rem;border-radius:1rem;background:linear-gradient(180deg,rgba(var(--bs-primary-rgb),.08),rgba(var(--bs-info-rgb),.04))}
    .tran-chip-row{display:flex;flex-wrap:wrap;gap:.5rem}
    .tran-lang-chip{display:inline-flex;align-items:center;gap:.45rem;border-radius:999px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.02);padding:.42rem .7rem}
    .tran-lang-chip small{color:var(--bs-secondary-color);font-size:.74rem}
    .tran-lang-chip.is-active{border-color:rgba(var(--bs-primary-rgb),.42);background:rgba(var(--bs-primary-rgb),.14)}
    .tran-swap-btn{min-width:120px;border-radius:999px}
    .tran-pane{padding:1rem;border-radius:1.2rem;background:rgba(255,255,255,.02)}
    .tran-pane--result{background:linear-gradient(180deg,rgba(var(--bs-info-rgb),.08),rgba(var(--bs-primary-rgb),.05));border-color:rgba(var(--bs-info-rgb),.16)}
    .tran-badge{border-radius:999px;padding:.45rem .7rem;background:rgba(255,255,255,.06);font-size:.72rem;letter-spacing:.06em;text-transform:uppercase}
    .tran-textarea{min-height:320px;resize:vertical;background:rgba(var(--bs-body-bg-rgb),.55);border-color:rgba(255,255,255,.08);line-height:1.8}
    .tran-output-shell{min-height:320px;border-radius:1rem;background:rgba(var(--bs-body-bg-rgb),.45);padding:1rem}
    .tran-output-text,.tran-history-snippet{word-break:break-word;unicode-bidi:plaintext;line-height:1.8;font-family:'montserrat',sans-serif}
    .tran-output-text{min-height:250px;max-height:420px;overflow-y:auto;font-family:'montserrat',sans-serif}
    .tran-output-empty{min-height:220px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;color:var(--bs-secondary-color)}
    .tran-cost-preview{background:rgba(var(--bs-primary-rgb),.08);border:1px solid rgba(var(--bs-primary-rgb),.18)}
    .tran-slider-shell{height:100%;padding:1rem;border-radius:1rem;border:1px solid rgba(255,255,255,.06);background:rgba(255,255,255,.02)}
    .tran-slider-badge{border-radius:999px;padding:.5rem .72rem;background:rgba(255,255,255,.92)!important;color:#0f172a!important;min-width:4.35rem;text-align:center;font-size:.78rem;letter-spacing:.02em}
    .tran-slider-scale{margin-top:.45rem}
    .tran-range{
        --tran-range-track: rgba(255,255,255,.08);
        margin-top:.75rem;
        -webkit-appearance:none;
        appearance:none;
        width:100%;
        height:1.1rem;
        background:transparent;
    }
    .tran-range:focus{outline:none}
    .tran-range::-webkit-slider-runnable-track{
        height:.36rem;
        border-radius:999px;
        background:var(--vz-gray);
    }
    .tran-range::-moz-range-track{
        height:.36rem;
        border-radius:999px;
        background:var(--vz-gray)
    }
    .tran-range::-webkit-slider-thumb{
        -webkit-appearance:none;
        appearance:none;
        width:1rem;
        height:1rem;
        margin-top:-0.32rem;
        border-radius:999px;
        border:2px solid rgba(255,255,255,.92);
        background:rgba(var(--bs-primary-rgb),1);
        box-shadow:0 0 0 4px rgba(var(--bs-primary-rgb),.12);
        cursor:pointer;
    }
    .tran-range::-moz-range-thumb{
        width:1rem;
        height:1rem;
        border-radius:999px;
        border:2px solid rgba(255,255,255,.92);
        background:rgba(var(--bs-primary-rgb),1);
        box-shadow:0 0 0 4px rgba(var(--bs-primary-rgb),.12);
        cursor:pointer;
    }
    .tran-range:focus::-webkit-slider-thumb{box-shadow:0 0 0 5px rgba(var(--vz-primary-text-emphasis),.18)}
    .tran-range:focus::-moz-range-thumb{box-shadow:0 0 0 5px rgba(var(--bs-primary-rgb),.18)}
    .tran-history-item--latest{background:linear-gradient(180deg,rgba(var(--bs-primary-rgb),.08),rgba(var(--bs-info-rgb),.04));border-color:rgba(var(--bs-primary-rgb),.18)!important}
    .tran-history-label{font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--bs-secondary-color);margin-bottom:.35rem}
    .tran-history-snippet{border-radius:.9rem;background:rgba(var(--bs-body-bg-rgb),.5);padding:.8rem .9rem}
    .tran-history-snippet--target{background:rgba(var(--bs-info-rgb),.06);border:1px solid rgba(var(--bs-info-rgb),.12)}
    @media (max-width: 991.98px){.tran-textarea,.tran-output-shell{min-height:240px}.tran-output-text{min-height:180px;max-height:320px}}
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('livewire:init',()=>{if(window.__tranCopyBooted)return;window.__tranCopyBooted=true;Livewire.on('tran-copy-text',payload=>{const d=Array.isArray(payload)?payload[0]:payload;const text=d&&typeof d.text!=='undefined'?d.text:'';if(text&&navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(text).catch(()=>{});}});});
</script>
@endpush
