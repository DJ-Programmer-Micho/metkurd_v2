<?php

use App\Models\Customer;
use App\Models\CustomerApiKey;
use App\Services\Billing\CustomerUsageSummaryService;
use App\Services\CustomerApi\CustomerApiAccessService;
use App\Services\CustomerApi\CustomerApiKeyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('app::layouts.app')]
class extends Component
{
    public bool $apiEnabled = false;

    public string $planName = 'Free';

    public string $planCode = 'free';

    public int $requestsPerMinute = 0;

    public int $concurrentJobs = 0;

    public int $creditBalance = 0;

    public int $monthlyAllowance = 0;

    public int $maxActiveKeys = 5;

    public int $activeKeyCount = 0;

    /** @var array<int, string> */
    public array $availableScopes = [];

    /** @var array<int, string> */
    public array $configuredAllowedTools = [];

    /** @var array<int, string> */
    public array $selectedScopes = [];

    /** @var array<int, array<string, mixed>> */
    public array $keys = [];

    public string $keyName = '';

    public ?string $justCreatedKey = null;

    public ?string $statusMessage = null;

    public string $statusType = 'info';

    public function mount(): void
    {
        $this->refreshPageState();
    }

    public function createKey(): void
    {
        $customer = $this->customer();

        if (! $customer instanceof Customer) {
            return;
        }

        if (! $this->apiEnabled) {
            $this->statusType = 'warning';
            $this->statusMessage = __('API access is available for paid subscriptions only.');

            return;
        }

        $allowedScopes = $this->availableScopes;
        $selectedScopes = $this->selectedScopes !== [] ? array_values($this->selectedScopes) : $allowedScopes;

        $validated = $this->validate([
            'keyName' => ['required', 'string', 'max:100'],
            'selectedScopes' => ['nullable', 'array'],
            'selectedScopes.*' => ['string', Rule::in($allowedScopes)],
        ], [
            'selectedScopes.*.in' => __('One or more scopes are not allowed for your current plan.'),
        ]);

        $activeKeys = app(CustomerApiKeyService::class)->activeKeysCount($customer);
        $maxKeys = max(1, (int) config('customer_api.max_keys', 5));

        if ($activeKeys >= $maxKeys) {
            $this->statusType = 'warning';
            $this->statusMessage = __('You have reached the maximum number of active API keys.');

            return;
        }

        try {
            $issued = app(CustomerApiKeyService::class)->issue(
                $customer,
                (string) $validated['keyName'],
                $selectedScopes
            );
        } catch (\RuntimeException $exception) {
            $this->statusType = 'warning';
            $this->statusMessage = $exception->getMessage();

            return;
        }

        $this->justCreatedKey = (string) $issued['plain_text_key'];
        $this->keyName = '';
        $this->selectedScopes = $this->availableScopes;
        $this->statusType = 'success';
        $this->statusMessage = __('Your API key was created. Copy it now. You will not be able to see it again.');

        $this->refreshPageState();
    }

    public function revokeKey(int $keyId): void
    {
        $customer = $this->customer();

        if (! $customer instanceof Customer) {
            return;
        }

        $key = CustomerApiKey::query()
            ->where('customer_id', (int) $customer->id)
            ->find($keyId);

        if (! $key instanceof CustomerApiKey) {
            $this->statusType = 'warning';
            $this->statusMessage = __('API key not found.');

            return;
        }

        if (! $key->isActive()) {
            $this->statusType = 'info';
            $this->statusMessage = __('This API key is already revoked.');
            $this->refreshPageState();

            return;
        }

        app(CustomerApiKeyService::class)->revoke($key);

        $this->statusType = 'success';
        $this->statusMessage = __('API key revoked successfully.');
        $this->refreshPageState();
    }

    protected function refreshPageState(): void
    {
        $customer = $this->customer();

        if (! $customer instanceof Customer) {
            return;
        }

        $access = app(CustomerApiAccessService::class);
        $usageSummary = app(CustomerUsageSummaryService::class)->forApiCustomer($customer);
        $config = $access->configForCustomer($customer);

        $this->apiEnabled = $access->customerHasApiAccess($customer);
        $this->planName = (string) ($config['plan']->name ?? __('Free'));
        $this->planCode = (string) ($config['plan']->code ?? 'free');
        $this->requestsPerMinute = (int) ($config['requests_per_minute'] ?? 0);
        $this->concurrentJobs = (int) ($config['concurrent_jobs'] ?? 0);
        $this->creditBalance = (int) data_get($usageSummary, 'credits.balance', 0);
        $this->monthlyAllowance = (int) data_get($usageSummary, 'credits.monthly', 0);
        $this->maxActiveKeys = max(1, (int) config('customer_api.max_keys', 5));
        $this->configuredAllowedTools = array_values((array) ($config['allowed_tools'] ?? []));
        $this->availableScopes = $access->availableScopesForCustomer($customer);
        $this->activeKeyCount = app(CustomerApiKeyService::class)->activeKeysCount($customer);

        $this->selectedScopes = collect($this->selectedScopes)
            ->map(fn (mixed $scope): string => strtolower(trim((string) $scope)))
            ->filter(fn (string $scope): bool => in_array($scope, $this->availableScopes, true))
            ->values()
            ->all();

        if ($this->selectedScopes === []) {
            $this->selectedScopes = $this->availableScopes;
        }

        $this->keys = CustomerApiKey::query()
            ->where('customer_id', (int) $customer->id)
            ->latest('id')
            ->get()
            ->map(fn (CustomerApiKey $key): array => [
                'id' => (int) $key->id,
                'name' => (string) $key->name,
                'prefix' => (string) $key->key_prefix,
                'scopes' => array_values((array) ($key->scopes ?? [])),
                'status' => $key->isActive() ? 'active' : 'revoked',
                'created_at' => $this->formatDate($key->created_at),
                'last_used_at' => $this->formatDate($key->last_used_at),
                'revoked_at' => $this->formatDate($key->revoked_at),
            ])
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string, note: string}>
     */
    public function scopeOptions(): array
    {
        return collect($this->availableScopes)
            ->map(function (string $scope): array {
                $meta = $this->scopeCatalogMap()[$scope] ?? [];

                return [
                    'value' => $scope,
                    'label' => (string) ($meta['label'] ?? strtoupper($scope)),
                    'note' => (string) ($meta['note'] ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    protected function customer(): ?Customer
    {
        $customer = auth('app')->user();

        return $customer instanceof Customer
            ? $customer->fresh(['profile', 'wallet', 'apiWallet', 'activeServiceSubscription.servicePlan'])
            : null;
    }

    protected function formatDate($value): ?string
    {
        if (! $value instanceof \DateTimeInterface) {
            return null;
        }

        return Carbon::instance($value)
            ->timezone(config('app.timezone'))
            ->format('Y-m-d H:i');
    }

    /**
     * @return array<string, array{label: string, note: string, category: string, available: bool}>
     */
    protected function scopeCatalogMap(): array
    {
        return [
            'usage:read' => [
                'label' => __('Usage reporting'),
                'note' => __('Read `/api/v1/usage` and inspect API wallet, rate-limit, and monthly credit summary data.'),
                'category' => __('Core'),
                'available' => true,
            ],
            'jobs:read' => [
                'label' => __('Job polling'),
                'note' => __('Poll async job state and completion metadata for submitted jobs.'),
                'category' => __('Core'),
                'available' => true,
            ],
            'files:download' => [
                'label' => __('File downloads'),
                'note' => __('Download completed output files from `/api/v1/files/{file_id}/download`.'),
                'category' => __('Core'),
                'available' => true,
            ],
            'tts:apollo-1-0v' => [
                'label' => __('Apollo 1.0 Voice'),
                'note' => __('List Apollo 1.0 Voice speakers and submit XTTS-backed synthesis jobs through the public product URL.'),
                'category' => __('TTS'),
                'available' => true,
            ],
            'tts:apollo-1-5v' => [
                'label' => __('Apollo 1.5 Voice'),
                'note' => __('List Apollo 1.5 Voice speakers and submit XOmni-backed synthesis jobs through the public product URL.'),
                'category' => __('TTS'),
                'available' => true,
            ],
            'tts:delta-1-0v' => [
                'label' => __('Delta 1.0 Voice'),
                'note' => __('List Delta 1.0 Voice speakers and submit F5TTS-backed synthesis jobs.'),
                'category' => __('TTS'),
                'available' => true,
            ],
            'tts:vector-1-0' => [
                'label' => __('Vector 1.0'),
                'note' => __('Submit Clone XTTS voice generation jobs with uploaded reference audio.'),
                'category' => __('TTS'),
                'available' => true,
            ],
            'tts:vector-1-5' => [
                'label' => __('Vector 1.5'),
                'note' => __('Submit Clone XOmni voice generation jobs with uploaded reference audio.'),
                'category' => __('TTS'),
                'available' => true,
            ],
            'asr:wasr' => [
                'label' => __('WASR transcription'),
                'note' => __('Submit WASR transcription jobs with uploaded audio.'),
                'category' => __('ASR'),
                'available' => true,
            ],
            'asr:qasr' => [
                'label' => __('QASR transcription'),
                'note' => __('Submit QASR transcription jobs with uploaded audio.'),
                'category' => __('ASR'),
                'available' => true,
            ],
            'caption:qasr' => [
                'label' => __('QASR captioning'),
                'note' => __('Submit subtitle and caption generation jobs through the QASR caption route.'),
                'category' => __('Caption'),
                'available' => true,
            ],
            'ocr:generate' => [
                'label' => __('OCR extraction'),
                'note' => __('Submit OCR extraction jobs for uploaded PDF documents.'),
                'category' => __('OCR'),
                'available' => true,
            ],
            'translation:generate' => [
                'label' => __('Translation'),
                'note' => __('Submit text translation jobs with source and target language control.'),
                'category' => __('Translation'),
                'available' => true,
            ],
            'stem:generate' => [
                'label' => __('Stem separation'),
                'note' => __('Submit 2-stem or 4-stem separation jobs for uploaded audio.'),
                'category' => __('STEM'),
                'available' => true,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function documentedScopes(): array
    {
        return collect($this->scopeCatalogMap())
            ->map(function (array $meta, string $scope): array {
                return [
                    'value' => $scope,
                    'label' => (string) $meta['label'],
                    'note' => (string) $meta['note'],
                    'category' => (string) $meta['category'],
                    'available' => (bool) $meta['available'],
                    'status_label' => (bool) $meta['available'] ? __('Available') : __('Coming soon'),
                    'status_class' => (bool) $meta['available'] ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function requestExamples(string $baseApiUrl): array
    {
        return [
            'auth' => [
                'title' => __('Authentication headers'),
                'format' => __('Headers'),
                'status' => __('Available'),
                'available' => true,
                'note' => __('Use the same bearer key and idempotency header format across all Public API calls.'),
                'code' => <<<'TEXT'
Authorization: Bearer mk_live_YOUR_KEY
Accept: application/json
Content-Type: application/json
Idempotency-Key: request-123
TEXT,
            ],
            'me' => [
                'title' => __('GET /api/v1/me'),
                'format' => __('cURL'),
                'status' => __('Available'),
                'available' => true,
                'note' => __('Returns the authenticated customer, current plan, API wallet balance, and current key scopes.'),
                'code' => <<<TEXT
curl -X GET "{$baseApiUrl}/me" \\
  -H "Authorization: Bearer mk_live_YOUR_KEY" \\
  -H "Accept: application/json"
TEXT,
            ],
            'usage' => [
                'title' => __('GET /api/v1/usage'),
                'format' => __('cURL'),
                'status' => __('Available'),
                'available' => true,
                'note' => __('Requires the `usage:read` scope and returns API usage summary data.'),
                'code' => <<<TEXT
curl -X GET "{$baseApiUrl}/usage" \\
  -H "Authorization: Bearer mk_live_YOUR_KEY" \\
  -H "Accept: application/json"
TEXT,
            ],
            'jobs' => [
                'title' => __('GET /api/v1/jobs/{job_id}'),
                'format' => __('cURL'),
                'status' => __('Available'),
                'available' => true,
                'note' => __('Poll a submitted async job until it completes or fails.'),
                'code' => <<<TEXT
curl -X GET "{$baseApiUrl}/jobs/job_01..." \\
  -H "Authorization: Bearer mk_live_YOUR_KEY" \\
  -H "Accept: application/json"
TEXT,
            ],
            'files' => [
                'title' => __('GET /api/v1/files/{file_id}/download'),
                'format' => __('cURL'),
                'status' => __('Available'),
                'available' => true,
                'note' => __('Returns a short-lived redirect to the generated result file.'),
                'code' => <<<TEXT
curl -L -X GET "{$baseApiUrl}/files/file_01.../download" \\
  -H "Authorization: Bearer mk_live_YOUR_KEY" \\
  -H "Accept: application/json"
TEXT,
            ],
            'tts_apollo_10' => [
                'title' => __('POST /api/v1/tts/apollo-1-0v'),
                'format' => __('cURL'),
                'status' => __('Available'),
                'available' => true,
                'note' => __('Apollo 1.0 Voice uses the XTTS engine behind a stable customer-facing product URL.'),
                'code' => <<<TEXT
curl -X POST "{$baseApiUrl}/tts/apollo-1-0v" \\
  -H "Authorization: Bearer mk_live_YOUR_KEY" \\
  -H "Accept: application/json" \\
  -H "Content-Type: application/json" \\
  -H "Idempotency-Key: apollo10-request-1" \\
  -d '{
    "text": "Slaw, ema taqikirdinewey API-ye MetKurd e.",
    "language": "ckb",
    "speaker_id": "your_apollo_10_voice_code",
    "storage": {
      "mode": "temporary"
    }
  }'
TEXT,
            ],
            'tts_apollo_15' => [
                'title' => __('POST /api/v1/tts/apollo-1-5v'),
                'format' => __('cURL'),
                'status' => __('Available'),
                'available' => true,
                'note' => __('Apollo 1.5 Voice uses the XOmni engine; the selected speaker supplies the reference audio server-side.'),
                'code' => <<<TEXT
curl -X POST "{$baseApiUrl}/tts/apollo-1-5v" \\
  -H "Authorization: Bearer mk_live_YOUR_KEY" \\
  -H "Accept: application/json" \\
  -H "Content-Type: application/json" \\
  -H "Idempotency-Key: apollo15-request-1" \\
  -d '{
    "text": "Slaw, ema taqikirdinewey Apollo 1.5 Voice API-ye MetKurd e.",
    "language": "ckb",
    "speaker_id": "your_apollo_15_voice_code",
    "storage": {
      "mode": "temporary"
    }
  }'
TEXT,
            ],
            'tts_delta_10' => [
                'title' => __('POST /api/v1/tts/delta-1-0v'),
                'format' => __('cURL'),
                'status' => __('Available'),
                'available' => true,
                'note' => __('Delta 1.0 Voice exposes the current F5TTS contract through the public product URL.'),
                'code' => <<<TEXT
curl -X POST "{$baseApiUrl}/tts/delta-1-0v" \\
  -H "Authorization: Bearer mk_live_YOUR_KEY" \\
  -H "Accept: application/json" \\
  -H "Content-Type: application/json" \\
  -H "Idempotency-Key: delta10-request-1" \\
  -d '{
    "text": "Slaw, ema taqikirdinewey Delta 1.0 Voice API-ye MetKurd e.",
    "speaker_id": "your_delta_10_voice_code",
    "use_ema": true,
    "nfe_step": 32,
    "cfg_strength": 2,
    "speed": 1,
    "storage": {
      "mode": "temporary"
    }
  }'
TEXT,
            ],
            'tts_vector_10' => [
                'title' => __('POST /api/v1/tts/vector-1-0'),
                'format' => __('cURL'),
                'status' => __('Available'),
                'available' => true,
                'note' => __('Vector 1.0 accepts multipart reference audio and returns a queued clone-voice job.'),
                'code' => <<<TEXT
curl -X POST "{$baseApiUrl}/tts/vector-1-0" \\
  -H "Authorization: Bearer mk_live_YOUR_KEY" \\
  -H "Accept: application/json" \\
  -H "Idempotency-Key: vector10-request-1" \\
  -F "text=Slaw, ema clone XTTS API test e." \\
  -F "language=ckb" \\
  -F "referenceAudio=@/path/to/reference.wav" \\
  -F "storage[mode]=temporary"
TEXT,
            ],
            'tts_vector_15' => [
                'title' => __('POST /api/v1/tts/vector-1-5'),
                'format' => __('cURL'),
                'status' => __('Available'),
                'available' => true,
                'note' => __('Vector 1.5 accepts multipart reference audio and routes to Clone XOmni internally.'),
                'code' => <<<TEXT
curl -X POST "{$baseApiUrl}/tts/vector-1-5" \\
  -H "Authorization: Bearer mk_live_YOUR_KEY" \\
  -H "Accept: application/json" \\
  -H "Idempotency-Key: vector15-request-1" \\
  -F "text=Slaw, ema clone XOmni API test e." \\
  -F "language=ckb" \\
  -F "referenceAudio=@/path/to/reference.wav" \\
  -F "storage[mode]=temporary"
TEXT,
            ],
            'asr_wasr' => [
                'title' => __('POST /api/v1/asr/wasr'),
                'format' => __('cURL'),
                'status' => __('Available'),
                'available' => true,
                'note' => __('Submit audio transcription through the public WASR endpoint.'),
                'code' => <<<TEXT
curl -X POST "{$baseApiUrl}/asr/wasr" \\
  -H "Authorization: Bearer mk_live_YOUR_KEY" \\
  -H "Accept: application/json" \\
  -H "Idempotency-Key: wasr-request-1" \\
  -F "audioFile=@/path/to/audio.wav" \\
  -F "language=ckb" \\
  -F "storage[mode]=temporary"
TEXT,
            ],
            'asr_qasr' => [
                'title' => __('POST /api/v1/asr/qasr'),
                'format' => __('cURL'),
                'status' => __('Available'),
                'available' => true,
                'note' => __('Submit audio transcription through the public QASR endpoint.'),
                'code' => <<<TEXT
curl -X POST "{$baseApiUrl}/asr/qasr" \\
  -H "Authorization: Bearer mk_live_YOUR_KEY" \\
  -H "Accept: application/json" \\
  -H "Idempotency-Key: qasr-request-1" \\
  -F "audioFile=@/path/to/audio.wav" \\
  -F "modelVariant=fine_tuned" \\
  -F "language=ckb" \\
  -F "storage[mode]=temporary"
TEXT,
            ],
            'caption_qasr' => [
                'title' => __('POST /api/v1/caption/qasr'),
                'format' => __('cURL'),
                'status' => __('Available'),
                'available' => true,
                'note' => __('Generate captions and SRT output through the public QASR caption endpoint.'),
                'code' => <<<TEXT
curl -X POST "{$baseApiUrl}/caption/qasr" \\
  -H "Authorization: Bearer mk_live_YOUR_KEY" \\
  -H "Accept: application/json" \\
  -H "Idempotency-Key: caption-qasr-request-1" \\
  -F "audioFile=@/path/to/media.mp4" \\
  -F "modelVariant=fine_tuned" \\
  -F "language=ckb" \\
  -F "outputFormat=srt" \\
  -F "returnSrt=true" \\
  -F "returnSegments=true" \\
  -F "storage[mode]=temporary"
TEXT,
            ],
            'ocr' => [
                'title' => __('POST /api/v1/ocr'),
                'format' => __('cURL'),
                'status' => __('Available'),
                'available' => true,
                'note' => __('Upload a PDF document for OCR extraction.'),
                'code' => <<<TEXT
curl -X POST "{$baseApiUrl}/ocr" \\
  -H "Authorization: Bearer mk_live_YOUR_KEY" \\
  -H "Accept: application/json" \\
  -H "Idempotency-Key: ocr-request-1" \\
  -F "documentFile=@/path/to/document.pdf" \\
  -F "lang=ckb+ara+eng" \\
  -F "storage[mode]=temporary"
TEXT,
            ],
            'translate' => [
                'title' => __('POST /api/v1/translate'),
                'format' => __('cURL'),
                'status' => __('Available'),
                'available' => true,
                'note' => __('Submit text translation jobs through the public translation endpoint.'),
                'code' => <<<TEXT
curl -X POST "{$baseApiUrl}/translate" \\
  -H "Authorization: Bearer mk_live_YOUR_KEY" \\
  -H "Accept: application/json" \\
  -H "Content-Type: application/json" \\
  -H "Idempotency-Key: translate-request-1" \\
  -d '{
    "text": "سڵاو، چۆنی؟",
    "sourceLang": "ku",
    "targetLang": "en",
    "storage": {
      "mode": "temporary"
    }
  }'
TEXT,
            ],
            'stem' => [
                'title' => __('POST /api/v1/stem'),
                'format' => __('cURL'),
                'status' => __('Available'),
                'available' => true,
                'note' => __('Submit 2-stem or 4-stem audio separation jobs.'),
                'code' => <<<TEXT
curl -X POST "{$baseApiUrl}/stem" \\
  -H "Authorization: Bearer mk_live_YOUR_KEY" \\
  -H "Accept: application/json" \\
  -H "Idempotency-Key: stem-request-1" \\
  -F "audioFile=@/path/to/song.mp3" \\
  -F "stems=2" \\
  -F "model=htdemucs_ft" \\
  -F "stemCodec=mp3" \\
  -F "stemBitrate=192k" \\
  -F "storage[mode]=temporary"
TEXT,
            ],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $requestExamples
     * @return array<int, array<string, mixed>>
     */
    protected function endpointGroups(string $baseApiUrl, array $requestExamples): array
    {
        $groups = [
            [
                'title' => __('Core'),
                'description' => __('Identity, usage, job polling, and file download endpoints.'),
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/me',
                        'route' => 'api.customer.v1.me',
                        'scope_badge' => __('Any active key'),
                        'scope_note' => __('Recommended for docs: `usage:read`'),
                        'content_type' => 'application/json',
                        'summary' => __('Inspect the authenticated customer, current plan, API wallet balance, and current key scopes.'),
                        'example_key' => 'me',
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/usage',
                        'route' => 'api.customer.v1.usage',
                        'scope_badge' => 'usage:read',
                        'scope_note' => __('Required by the live route.'),
                        'content_type' => 'application/json',
                        'summary' => __('Read API usage summaries together with rate-limit metadata.'),
                        'example_key' => 'usage',
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/jobs/{job_id}',
                        'route' => 'api.customer.v1.jobs.show',
                        'scope_badge' => __('Any active key'),
                        'scope_note' => __('Recommended for docs: `jobs:read`'),
                        'content_type' => 'application/json',
                        'summary' => __('Poll an async job until it completes, fails, or returns a downloadable result.'),
                        'example_key' => 'jobs',
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/files/{file_id}/download',
                        'route' => 'api.customer.v1.files.download',
                        'scope_badge' => __('Any active key'),
                        'scope_note' => __('Recommended for docs: `files:download`'),
                        'content_type' => __('Redirect to temporary file URL'),
                        'summary' => __('Redirect to a short-lived download URL for a completed result file.'),
                        'example_key' => 'files',
                    ],
                ],
            ],
            [
                'title' => __('TTS'),
                'description' => __('Public product/version TTS endpoints backed by the current internal synthesis engines.'),
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/tts/apollo-1-0v/voices',
                        'route' => 'api.customer.v1.tts.apollo10.voices',
                        'scope_badge' => 'tts:apollo-1-0v',
                        'scope_note' => __('Required by the live route.'),
                        'content_type' => 'application/json',
                        'summary' => __('List Apollo 1.0 Voice speaker IDs available to the current customer plan.'),
                        'example_key' => null,
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/tts/apollo-1-0v',
                        'route' => 'api.customer.v1.tts.apollo10.submit',
                        'scope_badge' => 'tts:apollo-1-0v',
                        'scope_note' => __('Required by the live route.'),
                        'content_type' => 'application/json',
                        'summary' => __('Submit an Apollo 1.0 Voice synthesis job with idempotent retry support.'),
                        'example_key' => 'tts_apollo_10',
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/tts/apollo-1-5v/voices',
                        'route' => 'api.customer.v1.tts.apollo15.voices',
                        'scope_badge' => 'tts:apollo-1-5v',
                        'scope_note' => __('Required by the live route.'),
                        'content_type' => 'application/json',
                        'summary' => __('List Apollo 1.5 Voice speaker IDs available to the current customer plan.'),
                        'example_key' => null,
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/tts/apollo-1-5v',
                        'route' => 'api.customer.v1.tts.apollo15.submit',
                        'scope_badge' => 'tts:apollo-1-5v',
                        'scope_note' => __('Required by the live route.'),
                        'content_type' => 'application/json',
                        'summary' => __('Submit an Apollo 1.5 Voice synthesis job using the same queued API workflow.'),
                        'example_key' => 'tts_apollo_15',
                    ],
                    [
                        'method' => 'GET',
                        'path' => '/tts/delta-1-0v/voices',
                        'route' => 'api.customer.v1.tts.delta10.voices',
                        'scope_badge' => 'tts:delta-1-0v',
                        'scope_note' => __('Required by the live route.'),
                        'content_type' => 'application/json',
                        'summary' => __('List Delta 1.0 Voice speaker IDs available to the current customer plan.'),
                        'example_key' => null,
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/tts/delta-1-0v',
                        'route' => 'api.customer.v1.tts.delta10.submit',
                        'scope_badge' => 'tts:delta-1-0v',
                        'scope_note' => __('Required by the live route.'),
                        'content_type' => 'application/json',
                        'summary' => __('Submit a Delta 1.0 Voice synthesis job.'),
                        'example_key' => 'tts_delta_10',
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/tts/vector-1-0',
                        'route' => 'api.customer.v1.tts.vector10.submit',
                        'scope_badge' => 'tts:vector-1-0',
                        'scope_note' => __('Required by the live route.'),
                        'content_type' => 'multipart/form-data',
                        'summary' => __('Submit a Vector 1.0 voice generation job using uploaded reference audio.'),
                        'example_key' => 'tts_vector_10',
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/tts/vector-1-5',
                        'route' => 'api.customer.v1.tts.vector15.submit',
                        'scope_badge' => 'tts:vector-1-5',
                        'scope_note' => __('Required by the live route.'),
                        'content_type' => 'multipart/form-data',
                        'summary' => __('Submit a Vector 1.5 voice generation job using uploaded reference audio.'),
                        'example_key' => 'tts_vector_15',
                    ],
                ],
            ],
            [
                'title' => __('ASR'),
                'description' => __('Speech-to-text endpoints for uploaded audio.'),
                'endpoints' => [
                    [
                        'method' => 'POST',
                        'path' => '/asr/wasr',
                        'route' => 'api.customer.v1.asr.wasr.submit',
                        'scope_badge' => 'asr:wasr',
                        'scope_note' => __('Required by the live route.'),
                        'content_type' => 'multipart/form-data',
                        'summary' => __('Submit a WASR transcription job.'),
                        'example_key' => 'asr_wasr',
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/asr/qasr',
                        'route' => 'api.customer.v1.asr.qasr.submit',
                        'scope_badge' => 'asr:qasr',
                        'scope_note' => __('Required by the live route.'),
                        'content_type' => 'multipart/form-data',
                        'summary' => __('Submit a QASR transcription job.'),
                        'example_key' => 'asr_qasr',
                    ],
                ],
            ],
            [
                'title' => __('Caption'),
                'description' => __('Caption generation APIs.'),
                'endpoints' => [
                    [
                        'method' => 'POST',
                        'path' => '/caption/qasr',
                        'route' => 'api.customer.v1.caption.qasr.submit',
                        'scope_badge' => 'caption:qasr',
                        'scope_note' => __('Required by the live route.'),
                        'content_type' => 'multipart/form-data',
                        'summary' => __('Submit a caption generation job for subtitle workflows.'),
                        'example_key' => 'caption_qasr',
                    ],
                ],
            ],
            [
                'title' => __('OCR'),
                'description' => __('OCR extraction APIs for uploaded documents.'),
                'endpoints' => [
                    [
                        'method' => 'POST',
                        'path' => '/ocr',
                        'route' => 'api.customer.v1.ocr.submit',
                        'scope_badge' => 'ocr:generate',
                        'scope_note' => __('Required by the live route.'),
                        'content_type' => 'multipart/form-data',
                        'summary' => __('Submit an OCR extraction job for a PDF document.'),
                        'example_key' => 'ocr',
                    ],
                ],
            ],
            [
                'title' => __('Translation'),
                'description' => __('Translation APIs for text transformation workflows.'),
                'endpoints' => [
                    [
                        'method' => 'POST',
                        'path' => '/translate',
                        'route' => 'api.customer.v1.translate.submit',
                        'scope_badge' => 'translation:generate',
                        'scope_note' => __('Required by the live route.'),
                        'content_type' => 'application/json',
                        'summary' => __('Submit a translation job for plain text input.'),
                        'example_key' => 'translate',
                    ],
                ],
            ],
            [
                'title' => __('STEM'),
                'description' => __('Audio stem separation APIs.'),
                'endpoints' => [
                    [
                        'method' => 'POST',
                        'path' => '/stem',
                        'route' => 'api.customer.v1.stem.submit',
                        'scope_badge' => 'stem:generate',
                        'scope_note' => __('Required by the live route.'),
                        'content_type' => 'multipart/form-data',
                        'summary' => __('Submit an audio stem separation job.'),
                        'example_key' => 'stem',
                    ],
                ],
            ],
        ];

        return collect($groups)
            ->map(function (array $group) use ($baseApiUrl, $requestExamples): array {
                $endpoints = collect((array) $group['endpoints'])
                    ->map(function (array $endpoint) use ($baseApiUrl, $requestExamples): array {
                        $available = $endpoint['route'] !== null && Route::has((string) $endpoint['route']);
                        $exampleKey = $endpoint['example_key'];
                        $example = is_string($exampleKey) ? ($requestExamples[$exampleKey] ?? null) : null;

                        return array_merge($endpoint, [
                            'available' => $available,
                            'status_label' => $available ? __('Available') : __('Unavailable'),
                            'status_class' => $available ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger',
                            'method_class' => $endpoint['method'] === 'POST' ? 'bg-info-subtle text-info' : 'bg-warning-subtle text-warning border',
                            'full_url' => $baseApiUrl.$endpoint['path'],
                            'curl_copy' => is_array($example) ? (string) $example['code'] : null,
                        ]);
                    })
                    ->values()
                    ->all();

                return [
                    'title' => (string) $group['title'],
                    'description' => (string) $group['description'],
                    'endpoints' => $endpoints,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function responseExamples(string $baseApiUrl): array
    {
        return [
            [
                'title' => __('Queued job response'),
                'format' => __('JSON'),
                'status' => __('Available'),
                'available' => true,
                'note' => __('Matches the live queued-response envelope returned by the current Public API job endpoints.'),
                'code' => <<<'JSON'
{
  "success": true,
  "job_id": "job_01...",
  "status": "queued",
  "estimated_credits": 45,
  "storage": {
    "mode": "temporary",
    "expires_in_days": 7
  }
}
JSON,
            ],
            [
                'title' => __('Completed file job response'),
                'format' => __('JSON'),
                'status' => __('Available'),
                'available' => true,
                'note' => __('Matches the current live file-result envelope returned by `GET /api/v1/jobs/{job_id}` after completion.'),
                'code' => <<<JSON
{
  "success": true,
  "job_id": "job_01...",
  "status": "completed",
  "estimated_credits": 45,
  "credits_charged": 43,
  "storage": {
    "mode": "temporary",
    "expires_in_days": 7
  },
  "result": {
    "file_id": "file_01...",
    "mime_type": "audio/mpeg",
    "size_bytes": 582144,
    "download_url": "{$baseApiUrl}/files/file_01.../download",
    "expires_at": "2026-06-21T12:00:00Z"
  }
}
JSON,
            ],
            [
                'title' => __('Completed text job response'),
                'format' => __('JSON'),
                'status' => __('Available'),
                'available' => true,
                'note' => __('Representative completed response for text-returning endpoints such as translation, OCR, or ASR.'),
                'code' => <<<'JSON'
{
  "success": true,
  "job_id": "job_01...",
  "status": "completed",
  "credits_charged": 21,
  "result": {
    "text": "Hello, how are you?",
    "source_lang": "ckb",
    "target_lang": "en"
  }
}
JSON,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function errorCatalog(): array
    {
        return [
            ['code' => 'invalid_api_key', 'status' => 401, 'note' => __('The bearer key is missing, revoked, or unknown.')],
            ['code' => 'api_access_required', 'status' => 403, 'note' => __('Free plans and API-disabled customers are blocked by the shared Public API access middleware.')],
            ['code' => 'scope_forbidden', 'status' => 403, 'note' => __('The active key or current paid plan does not allow the requested live scope.')],
            ['code' => 'insufficient_credits', 'status' => 402, 'note' => __('The API wallet does not have enough credits to reserve the requested job.')],
            ['code' => 'idempotency_conflict', 'status' => 409, 'note' => __('The same `Idempotency-Key` was reused with a different request payload.')],
            ['code' => 'concurrency_limit_exceeded', 'status' => 409, 'note' => __('The customer already has the maximum number of active API jobs for the current plan.')],
            ['code' => 'job_not_found', 'status' => 404, 'note' => __('The requested job does not belong to the authenticated customer or does not exist.')],
            ['code' => 'file_unavailable', 'status' => 404, 'note' => __('The result file has been deleted, is missing from storage, or is otherwise unavailable.')],
            ['code' => 'file_expired', 'status' => 410, 'note' => __('The temporary result file has expired and can no longer be downloaded.')],
            ['code' => 'request_failed', 'status' => 422, 'note' => __('Validation or request submission failed before a job could be accepted.')],
            ['code' => 'pricing_unavailable', 'status' => 422, 'note' => __('API pricing is not configured for the requested action.')],
            ['code' => 'rate_limit_exceeded', 'status' => 429, 'note' => __('The customer exceeded the current plan request-per-minute limit.')],
        ];
    }

    public function render()
    {
        $baseApiUrl = url('/api/v1');
        $requestExamples = $this->requestExamples($baseApiUrl);

        return view()->file(resource_path('views/app/pages/api/⚡app-api-access.blade.php'), [
            'scopeOptions' => $this->scopeOptions(),
            'documentedScopes' => $this->documentedScopes(),
            'baseApiUrl' => $baseApiUrl,
            'apiPageUrl' => route('app.api-access', ['locale' => app()->getLocale()]),
            'endpointGroups' => $this->endpointGroups($baseApiUrl, $requestExamples),
            'requestExamples' => array_values($requestExamples),
            'responseExamples' => $this->responseExamples($baseApiUrl),
            'errorExamples' => $this->errorCatalog(),
            'freeAccessErrorExample' => <<<'JSON'
{
  "success": false,
  "message": "API access is available only on paid plans.",
  "code": "api_access_required"
}
JSON,
            'securityNotes' => [
                __('Store API keys only on trusted servers. Never embed a live key inside public frontend bundles or mobile apps.'),
                __('Rotate keys when team membership changes, a deployment environment is retired, or a key appears in logs.'),
                __('Use `Idempotency-Key` on every POST so retries do not create duplicate jobs or duplicate API wallet reservations.'),
                __('Temporary storage is safer for one-off delivery; permanent storage should be used only when the customer needs durable retention.'),
            ],
        ]);
    }
};
?>

<x-slot:title>{{ __('API Access') }} | {{ __('MET KURD') }}</x-slot:title>

@php
    $creditPercent = $monthlyAllowance > 0 ? max(0, min(100, (int) round(($creditBalance / $monthlyAllowance) * 100))) : 0;
@endphp

<style>
    .api-doc-code {
        background: #0f172a;
        color: #e2e8f0;
        border: 1px solid rgba(148, 163, 184, 0.2);
        border-radius: 1rem;
        padding: 1rem;
    }

    .api-doc-code pre,
    .api-doc-code code {
        white-space: pre;
        overflow-x: auto;
    }

    .api-doc-copy-row {
        gap: 0.5rem;
    }

    .api-doc-pill {
        border: 1px solid rgba(148, 163, 184, 0.2);
        border-radius: 999px;
        padding: 0.35rem 0.75rem;
        background: rgba(255, 255, 255, 0.04);
    }
</style>

<script>
    window.copyApiDocText = function (button) {
        const text = button?.dataset?.copy ?? '';
        if (!text || !navigator.clipboard?.writeText) {
            return;
        }

        navigator.clipboard.writeText(text).catch(() => {});
    };
</script>

<div class="container-fluid py-4">
    <div class="row g-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm overflow-hidden">
                <div class="card-body p-4 p-lg-5 bg-info-subtle">
                    <div class="d-flex flex-column flex-xl-row justify-content-between gap-4">
                        <div>
                            <div class="text-uppercase small fw-semibold text-info mb-2">{{ __('Overview') }}</div>
                            <h2 class="mb-2">{{ __('Public Customer API v1') }}</h2>
                            <p class="text-muted mb-3">
                                {{ __('Use scoped keys, separate API wallet credits, and plan-aware limits to call MetKurd from your own backend or automation stack. Every endpoint listed on this page is live and uses the current product/version URL contract.') }}
                            </p>
                            <div class="d-flex flex-wrap api-doc-copy-row">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-info"
                                    data-copy="{{ $baseApiUrl }}"
                                    onclick="copyApiDocText(this)"
                                >
                                    {{ __('Copy Base URL') }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-info"
                                    data-copy="Authorization: Bearer mk_live_YOUR_KEY"
                                    onclick="copyApiDocText(this)"
                                >
                                    {{ __('Copy Auth Header') }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-info"
                                    data-copy="Idempotency-Key: request-123"
                                    onclick="copyApiDocText(this)"
                                >
                                    {{ __('Copy Idempotency Header') }}
                                </button>
                            </div>
                        </div>
                        <div class="text-xl-end">
                            <span class="badge {{ $apiEnabled ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' }} fs-6 px-3 py-2">
                                {{ $apiEnabled ? __('API Enabled') : __('Paid Plan Required') }}
                            </span>
                            <div class="small text-muted mt-3">
                                {{ __('Base URL') }}:
                                <code>{{ $baseApiUrl }}</code>
                            </div>
                            <div class="small text-muted mt-1">{{ __('Current Plan') }}: <strong>{{ $planName }}</strong></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <div class="text-uppercase small fw-semibold text-muted">{{ __('API Limits & Credits') }}</div>
                            <h5 class="mb-0">{{ __('Current Access Summary') }}</h5>
                        </div>
                        <span class="badge bg-secondary-subtle text-secondary">{{ strtoupper($planCode) }}</span>
                    </div>

                    <div class="vstack gap-3">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">{{ __('Current Plan') }}</span>
                            <span class="fw-semibold">{{ $planName }}</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">{{ __('API Status') }}</span>
                            <span class="fw-semibold">{{ $apiEnabled ? __('Enabled') : __('Disabled') }}</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">{{ __('API Requests / Minute') }}</span>
                            <span class="fw-semibold">{{ number_format($requestsPerMinute) }}</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">{{ __('API Concurrent Jobs') }}</span>
                            <span class="fw-semibold">{{ number_format($concurrentJobs) }}</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">{{ __('Active Keys') }}</span>
                            <span class="fw-semibold">{{ $activeKeyCount }} / {{ $maxActiveKeys }}</span>
                        </div>
                    </div>

                    <hr>

                    <div class="text-uppercase small fw-semibold text-muted mb-2">{{ __('Your API Credits') }}</div>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-muted">{{ __('API Credits Remaining') }}</span>
                        <span class="fw-semibold">{{ number_format($creditBalance) }}</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted">{{ __('API Monthly Allowance') }}</span>
                        <span class="fw-semibold">{{ number_format($monthlyAllowance) }}</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-success" style="width: {{ $monthlyAllowance > 0 ? $creditPercent : 0 }}%;"></div>
                    </div>

                    <hr>

                    <div class="text-uppercase small fw-semibold text-muted mb-2">{{ __('Available Key Scopes On Your Plan') }}</div>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        @forelse($availableScopes as $scope)
                            <span class="badge bg-dark text-white border">{{ $scope }}</span>
                        @empty
                            <span class="text-muted small">{{ __('No API scopes are available on the current plan.') }}</span>
                        @endforelse
                    </div>

                    <div class="text-uppercase small fw-semibold text-muted mb-2">{{ __('Configured Plan Scopes') }}</div>
                    <div class="d-flex flex-wrap gap-2">
                        @forelse($configuredAllowedTools as $scope)
                            <span class="badge bg-body text-body border">{{ $scope }}</span>
                        @empty
                            <span class="text-muted small">{{ __('No plan scopes configured yet.') }}</span>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                        <div>
                            <div class="text-uppercase small fw-semibold text-muted">{{ __('Authentication') }}</div>
                            <h5 class="mb-1">{{ __('Bearer keys + idempotent requests') }}</h5>
                            <p class="text-muted mb-0">
                                {{ __('Every request uses a scoped bearer key. All mutating calls should include `Idempotency-Key` so retries do not create duplicate jobs or duplicate API wallet reservations.') }}
                            </p>
                        </div>
                        <a href="{{ $baseApiUrl }}/me" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noreferrer">
                            {{ __('Open Base Endpoint') }}
                        </a>
                    </div>

                    <div class="api-doc-code">
                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                            <span class="small text-uppercase text-info fw-semibold">{{ __('Headers') }}</span>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-info"
                                data-copy="Authorization: Bearer mk_live_YOUR_KEY&#10;Accept: application/json&#10;Content-Type: application/json&#10;Idempotency-Key: request-123"
                                onclick="copyApiDocText(this)"
                            >
                                {{ __('Copy Headers') }}
                            </button>
                        </div>
                        <pre class="mb-0 overflow-auto"><code>Authorization: Bearer mk_live_YOUR_KEY
Accept: application/json
Content-Type: application/json
Idempotency-Key: request-123</code></pre>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                        <div>
                            <div class="text-uppercase small fw-semibold text-muted">{{ __('API Keys') }}</div>
                            <h5 class="mb-0">{{ __('API Keys') }}</h5>
                        </div>
                        <span class="text-muted small">{{ __('Full keys are shown only once after creation.') }}</span>
                    </div>

                    @if($statusMessage)
                        <div class="alert alert-{{ $statusType }} mb-3">{{ $statusMessage }}</div>
                    @endif

                    @if($justCreatedKey)
                        <div class="card border-success border-1 bg-success-subtle mb-4">
                            <div class="card-body">
                                <div class="fw-semibold text-success mb-2">{{ __('Your API key was created. Copy it now. You will not be able to see it again.') }}</div>
                                <div class="input-group">
                                    <input id="created-api-key" type="text" class="form-control" readonly value="{{ $justCreatedKey }}">
                                    <button
                                        type="button"
                                        class="btn btn-success"
                                        data-copy="{{ $justCreatedKey }}"
                                        onclick="copyApiDocText(this)"
                                    >
                                        {{ __('Copy') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endif

                    @if($apiEnabled)
                        <form wire:submit="createKey" class="border rounded-3 p-3 mb-4">
                            <div class="row g-3">
                                <div class="col-lg-5">
                                    <label class="form-label">{{ __('Key Name') }}</label>
                                    <input type="text" class="form-control" wire:model="keyName" maxlength="100" placeholder="{{ __('Production server') }}">
                                    @error('keyName') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-lg-7">
                                    <div class="small text-muted text-uppercase fw-semibold mb-2">{{ __('Scope Selection') }}</div>
                                    <div class="small text-muted">{{ __('Choose the scopes this key should have. API key scopes cannot exceed your current plan API entitlements.') }}</div>
                                </div>

                                <div class="col-12">
                                    <div class="row g-2">
                                        @foreach($scopeOptions as $scopeOption)
                                            <div class="col-md-6">
                                                <label class="border rounded-3 p-3 d-block h-100">
                                                    <div class="form-check">
                                                        <input
                                                            class="form-check-input"
                                                            type="checkbox"
                                                            value="{{ $scopeOption['value'] }}"
                                                            wire:model="selectedScopes"
                                                            id="scope-{{ md5($scopeOption['value']) }}"
                                                        >
                                                        <span class="form-check-label fw-semibold">{{ $scopeOption['label'] }}</span>
                                                    </div>
                                                    <div class="small text-muted mt-2">{{ $scopeOption['note'] }}</div>
                                                    <div class="small mt-2"><code>{{ $scopeOption['value'] }}</code></div>
                                                </label>
                                            </div>
                                        @endforeach
                                    </div>
                                    @error('selectedScopes.*') <div class="text-danger small mt-2">{{ $message }}</div> @enderror
                                </div>

                                <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <div class="small text-muted">{{ __('Maximum active keys: :count', ['count' => $maxActiveKeys]) }}</div>
                                    <button type="submit" class="btn btn-info" @disabled($activeKeyCount >= $maxActiveKeys)>
                                        {{ __('Generate API Key') }}
                                    </button>
                                </div>
                            </div>
                        </form>
                    @else
                        <div class="card border-warning bg-warning-subtle mb-4">
                            <div class="card-body">
                                <div class="text-uppercase small fw-semibold text-warning mb-2">{{ __('Locked State') }}</div>
                                <h5 class="mb-2">{{ __('API Access is available for paid subscriptions only.') }}</h5>
                                <p class="text-muted mb-3">
                                    {{ __('Free customers can review the API documentation preview, but key generation and `/api/v1` access are locked until a paid subscription enables API access.') }}
                                </p>
                                <div class="d-flex flex-wrap gap-2">
                                    <a wire:navigate href="{{ route('subscription-plan', ['locale' => app()->getLocale()]) }}" class="btn btn-warning btn-sm">
                                        {{ __('View Plans') }}
                                    </a>
                                    <span class="btn btn-outline-secondary btn-sm disabled">{{ __('Docs Preview') }}</span>
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="text-uppercase small fw-semibold text-muted mb-2">{{ __('Documented API Scope Catalog') }}</div>
                    <div class="row g-3 mb-4">
                        @foreach($documentedScopes as $scope)
                            <div class="col-xl-4 col-md-6">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                        <span class="badge bg-dark text-white border">{{ $scope['category'] }}</span>
                                        <span class="badge {{ $scope['status_class'] }}">{{ $scope['status_label'] }}</span>
                                    </div>
                                    <div class="fw-semibold mb-1">{{ $scope['label'] }}</div>
                                    <div class="small mb-2"><code>{{ $scope['value'] }}</code></div>
                                    <div class="small text-muted">{{ $scope['note'] }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('Name') }}</th>
                                    <th>{{ __('Prefix') }}</th>
                                    <th>{{ __('Scopes') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Created At') }}</th>
                                    <th>{{ __('Last Used') }}</th>
                                    <th class="text-end">{{ __('Revoke') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($keys as $key)
                                    <tr class="{{ $key['status'] === 'revoked' ? 'opacity-75' : '' }}">
                                        <td class="fw-semibold">{{ $key['name'] }}</td>
                                        <td><code>{{ $key['prefix'] }}</code></td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-1">
                                                @foreach($key['scopes'] as $scope)
                                                    <span class="badge bg-warning text-warning border">{{ $scope }}</span>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge {{ $key['status'] === 'active' ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }}">
                                                {{ __($key['status'] === 'active' ? 'Active' : 'Revoked') }}
                                            </span>
                                        </td>
                                        <td>{{ $key['created_at'] ?? __('Never') }}</td>
                                        <td>{{ $key['last_used_at'] ?? __('Never') }}</td>
                                        <td class="text-end">
                                            @if($key['status'] === 'active')
                                                <button type="button" class="btn btn-sm btn-outline-danger" wire:click="revokeKey({{ $key['id'] }})">
                                                    {{ __('Revoke') }}
                                                </button>
                                            @else
                                                <span class="text-muted small">{{ __('Revoked') }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">{{ __('No API keys created yet.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="text-uppercase small fw-semibold text-muted mb-2">{{ __('Endpoint Catalog') }}</div>
                    <h5 class="mb-3">{{ __('Public API v1 endpoint groups') }}</h5>
                    <p class="text-muted mb-4">
                        {{ __('Each card shows the HTTP method, path, full URL, content type, current availability, and a copyable cURL example when one exists.') }}
                    </p>

                    <div class="row g-4">
                        @foreach($endpointGroups as $group)
                            <div class="col-12">
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                    <div>
                                        <h6 class="mb-1">{{ $group['title'] }}</h6>
                                        <div class="small text-muted">{{ $group['description'] }}</div>
                                    </div>
                                    <span class="api-doc-pill small text-muted">{{ count($group['endpoints']) }} {{ __('endpoints') }}</span>
                                </div>

                                <div class="row g-3">
                                    @foreach($group['endpoints'] as $endpoint)
                                        <div class="col-xxl-3 col-xl-4 col-md-6">
                                            <div class="border rounded-3 p-3 h-100">
                                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <span class="badge {{ $endpoint['method_class'] }}">{{ $endpoint['method'] }}</span>
                                                        <span class="badge {{ $endpoint['status_class'] }}">{{ $endpoint['status_label'] }}</span>
                                                    </div>
                                                    <span class="badge bg-body text-body border">{{ $endpoint['scope_badge'] }}</span>
                                                </div>

                                                <div class="fw-semibold mb-1"><code>{{ $endpoint['path'] }}</code></div>
                                                <div class="small text-muted mb-2">{{ $endpoint['summary'] }}</div>
                                                <div class="small text-muted mb-1">{{ __('Full URL') }}: <code>{{ $endpoint['full_url'] }}</code></div>
                                                <div class="small text-muted mb-1">{{ __('Content-Type') }}: <code>{{ $endpoint['content_type'] }}</code></div>
                                                <div class="small text-muted mb-3">{{ __('Scope') }}: {{ $endpoint['scope_note'] }}</div>

                                                <div class="d-flex flex-wrap api-doc-copy-row">
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-outline-info"
                                                        data-copy="{{ $endpoint['path'] }}"
                                                        onclick="copyApiDocText(this)"
                                                    >
                                                        {{ __('Copy Path') }}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-outline-info"
                                                        data-copy="{{ $endpoint['full_url'] }}"
                                                        onclick="copyApiDocText(this)"
                                                    >
                                                        {{ __('Copy Full URL') }}
                                                    </button>
                                                    @if($endpoint['curl_copy'])
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-outline-info"
                                                            data-copy="{{ $endpoint['curl_copy'] }}"
                                                            onclick="copyApiDocText(this)"
                                                        >
                                                            {{ __('Copy cURL') }}
                                                        </button>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="text-uppercase small fw-semibold text-muted mb-2">{{ __('Request Examples') }}</div>
                    <h5 class="mb-3">{{ __('Copyable request examples') }}</h5>

                    <div class="row g-4">
                        @foreach($requestExamples as $example)
                            <div class="col-xxl-4 col-xl-6">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                        <div>
                                            <div class="fw-semibold">{{ $example['title'] }}</div>
                                            <div class="small text-muted">{{ $example['note'] }}</div>
                                        </div>
                                        <span class="badge {{ $example['available'] ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }}">
                                            {{ $example['status'] }}
                                        </span>
                                    </div>

                                    <div class="api-doc-code">
                                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                            <span class="small text-uppercase text-info fw-semibold">{{ $example['format'] }}</span>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-info"
                                                data-copy="{{ $example['code'] }}"
                                                onclick="copyApiDocText(this)"
                                            >
                                                {{ __('Copy') }}
                                            </button>
                                        </div>
                                        <pre class="mb-0 overflow-auto"><code>{{ $example['code'] }}</code></pre>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-6">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-uppercase small fw-semibold text-muted mb-2">{{ __('Storage Modes') }}</div>
                    <h5 class="mb-3">{{ __('Temporary vs permanent files') }}</h5>
                    <ul class="mb-3 ps-3 text-muted">
                        <li class="mb-2">{{ __('`temporary` is the current Public API default and keeps files outside the customer storage quota with automatic expiry.') }}</li>
                        <li class="mb-2">{{ __('`permanent` stores files in customer storage, counts toward quota, and remains until the customer deletes them.') }}</li>
                        <li>{{ __('Use temporary storage for delivery workflows and permanent storage only when the customer explicitly needs retention.') }}</li>
                    </ul>

                    <button
                        type="button"
                        class="btn btn-sm btn-outline-info"
                        data-copy='{"storage":{"mode":"temporary"}}'
                        onclick="copyApiDocText(this)"
                    >
                        {{ __('Copy Temporary Storage JSON') }}
                    </button>
                </div>
            </div>
        </div>

        <div class="col-xl-6">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-uppercase small fw-semibold text-muted mb-2">{{ __('Idempotency') }}</div>
                    <h5 class="mb-3">{{ __('Safe retries') }}</h5>
                    <p class="text-muted mb-2">
                        {{ __('Every POST request should carry a unique `Idempotency-Key`. When the same key is reused with the same payload, the API returns the existing job instead of creating a duplicate.') }}
                    </p>

                    <div class="api-doc-code">
                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                            <span class="small text-uppercase text-info fw-semibold">{{ __('Header') }}</span>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-info"
                                data-copy="Idempotency-Key: your-request-id-1"
                                onclick="copyApiDocText(this)"
                            >
                                {{ __('Copy Header') }}
                            </button>
                        </div>
                        <pre class="mb-0 overflow-auto"><code>Idempotency-Key: your-request-id-1</code></pre>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-12">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-uppercase small fw-semibold text-muted mb-2">{{ __('Response & Errors') }}</div>
                    <h5 class="mb-3">{{ __('Free-plan access contract') }}</h5>
                    <div class="api-doc-code mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                            <span class="small text-uppercase text-info fw-semibold">{{ __('JSON') }}</span>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-info"
                                data-copy="{{ $freeAccessErrorExample }}"
                                onclick="copyApiDocText(this)"
                            >
                                {{ __('Copy Error JSON') }}
                            </button>
                        </div>
                        <pre class="mb-0 overflow-auto"><code>{{ $freeAccessErrorExample }}</code></pre>
                    </div>

                    <div class="row g-2 p-3">
                        @foreach($errorExamples as $error)
                            <div class="col-12 col-md-6 border rounded-3 p-2">
                                <div class="d-flex justify-content-between">
                                    <code>{{ $error['code'] }}</code>
                                    <span class="badge bg-dark text-danger border">{{ $error['status'] }}</span>
                                </div>
                                <div class="small text-muted mt-1">{{ $error['note'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="text-uppercase small fw-semibold text-muted mb-2">{{ __('Response Examples') }}</div>
                    <h5 class="mb-3">{{ __('Reference payloads') }}</h5>

                    <div class="row g-4">
                        @foreach($responseExamples as $example)
                            <div class="col-xl-4">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                        <div>
                                            <div class="fw-semibold">{{ $example['title'] }}</div>
                                            <div class="small text-muted">{{ $example['note'] }}</div>
                                        </div>
                                        <span class="badge {{ $example['available'] ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }}">
                                            {{ $example['status'] }}
                                        </span>
                                    </div>

                                    <div class="api-doc-code">
                                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                            <span class="small text-uppercase text-info fw-semibold">{{ $example['format'] }}</span>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-info"
                                                data-copy="{{ $example['code'] }}"
                                                onclick="copyApiDocText(this)"
                                            >
                                                {{ __('Copy') }}
                                            </button>
                                        </div>
                                        <pre class="mb-0 overflow-auto"><code>{{ $example['code'] }}</code></pre>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="text-uppercase small fw-semibold text-muted mb-2">{{ __('Security Notes') }}</div>
                    <h5 class="mb-3">{{ __('Operational guidance') }}</h5>
                    <div class="row g-3">
                        @foreach($securityNotes as $note)
                            <div class="col-lg-6">
                                <div class="border rounded-3 p-3 h-100 text-muted">
                                    {{ $note }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
