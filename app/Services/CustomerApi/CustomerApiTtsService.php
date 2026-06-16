<?php

namespace App\Services\CustomerApi;

use App\Models\ApiJob;
use App\Models\ApiUsageLog;
use App\Models\Customer;
use App\Models\CustomerApiKey;
use App\Models\MlJob;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Services\Providers\RunPodProvider;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CustomerApiTtsService
{
    public function __construct(
        protected CustomerApiAccessService $access,
        protected CustomerApiScopeService $scopes,
        protected CustomerApiVoiceCatalog $voices,
        protected CustomerApiCreditReservationService $reservations,
        protected CustomerApiJobSyncService $jobs,
        protected RunPodProvider $runpod,
    ) {}

    /**
     * @return array{api_job: ApiJob, created: bool}
     */
    public function submitXtts(Request $request, Customer $customer, CustomerApiKey $apiKey): array
    {
        return $this->submitTtsJob(
            request: $request,
            customer: $customer,
            apiKey: $apiKey,
            scope: 'tts:apollo-1-0v',
            engine: 'xtts',
            toolCode: 'tts',
            actionCode: 'tts.standard',
            languageDefault: 'ar',
            endpointConfig: 'runpod.endpoints.xtts',
            providerPayload: function (array $validated, array $context): array {
                return [
                    'text' => trim((string) $validated['text']),
                    'language' => (string) $validated['language'],
                    'speaker' => (string) $validated['speaker_id'],
                    'enable_text_splitting' => (bool) $validated['split'],
                    'max_words' => (int) $validated['max_words'],
                    'temperature' => (float) $validated['temperature'],
                    'length_penalty' => (float) $validated['length_penalty'],
                    'repetition_penalty' => (float) $validated['repetition_penalty'],
                    'top_k' => (int) $validated['top_k'],
                    'top_p' => (float) $validated['top_p'],
                    'speed' => (float) $validated['speed'],
                ];
            },
        );
    }

    /**
     * @return array{api_job: ApiJob, created: bool}
     */
    public function submitXomni(Request $request, Customer $customer, CustomerApiKey $apiKey): array
    {
        return $this->submitTtsJob(
            request: $request,
            customer: $customer,
            apiKey: $apiKey,
            scope: 'tts:apollo-1-5v',
            engine: 'xomni',
            toolCode: 'xomni',
            actionCode: 'xomni.generate',
            languageDefault: 'ckb',
            endpointConfig: 'runpod.endpoints.omni',
            providerPayload: function (array $validated, array $context): array {
                return [
                    'mode' => 'builtin_ref',
                    'text' => trim((string) $validated['text']),
                    'ref_audio' => (string) ($context['ref_audio'] ?? ''),
                    'ref_text' => '',
                    'language' => (string) $validated['language'],
                    'output_format' => 'wav',
                    'return_base64' => true,
                ];
            },
        );
    }

    /**
     * @param  callable(array, array): array<string, mixed>  $providerPayload
     * @return array{api_job: ApiJob, created: bool}
     */
    protected function submitTtsJob(
        Request $request,
        Customer $customer,
        CustomerApiKey $apiKey,
        string $scope,
        string $engine,
        string $toolCode,
        string $actionCode,
        string $languageDefault,
        string $endpointConfig,
        callable $providerPayload
    ): array {
        if (! $this->scopes->hasScope($apiKey, $scope)) {
            throw new \RuntimeException('This API key is not authorized for the requested scope.');
        }

        if (! $this->access->allowsScope($customer, $scope)) {
            throw new \RuntimeException('Your current plan does not allow this API scope.');
        }

        if (! $customer->isAllowed($actionCode, \App\Models\PlanEntitlement::CHANNEL_API)) {
            throw new \RuntimeException('Your current plan does not allow this tool.');
        }

        $availableVoices = $this->voices->voicesForCustomer($customer, $engine)
            ->mapWithKeys(fn ($voice): array => [(string) $voice->code => (string) $voice->name])
            ->all();

        if ($availableVoices === []) {
            throw new \RuntimeException('No voices are available for your current plan.');
        }

        $defaults = [
            'speaker_id' => (string) array_key_first($availableVoices),
            'language' => $languageDefault,
            'split' => true,
            'max_words' => 25,
            'fade_ms' => 80,
            'temperature' => 0.65,
            'top_k' => 50,
            'top_p' => 0.8,
            'repetition_penalty' => 2.0,
            'length_penalty' => 1.0,
            'speed' => 1.0,
            'storage' => ['mode' => 'temporary'],
        ];

        $data = array_replace_recursive($defaults, (array) $request->all());
        $validated = Validator::make($data, [
            'text' => ['required', 'string', 'min:1', 'max:4000'],
            'speaker_id' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($availableVoices): void {
                if (! array_key_exists((string) $value, $availableVoices)) {
                    $fail(__('The selected speaker is not available for your plan.'));
                }
            }],
            'language' => ['required', 'string', 'min:1', 'max:8'],
            'split' => ['boolean'],
            'max_words' => ['required', 'integer', 'min:5', 'max:80'],
            'fade_ms' => ['required', 'integer', 'min:0', 'max:1000'],
            'temperature' => ['required', 'numeric', 'min:0', 'max:2.5'],
            'top_k' => ['required', 'integer', 'min:0', 'max:100'],
            'top_p' => ['required', 'numeric', 'min:0', 'max:1'],
            'repetition_penalty' => ['required', 'numeric', 'min:1', 'max:8'],
            'length_penalty' => ['required', 'numeric', 'min:-5', 'max:6'],
            'speed' => ['required', 'numeric', 'min:0.5', 'max:2.0'],
            'storage.mode' => ['nullable', 'string', 'in:temporary,permanent'],
        ])->validate();

        $storageMode = (string) data_get($validated, 'storage.mode', 'temporary');
        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        $requestHash = hash('sha256', json_encode([
            'tool' => $toolCode,
            'action' => $actionCode,
            'payload' => $validated,
        ], JSON_THROW_ON_ERROR));

        $usageLog = $this->claimIdempotencyKey($request, $customer, $apiKey, $idempotencyKey, $requestHash);

        if ($usageLog->api_job_id) {
            $existingJob = ApiJob::query()->findOrFail((string) $usageLog->api_job_id);

            return [
                'api_job' => $existingJob->fresh(['mlJob.tool', 'resultFiles.storageFile']) ?: $existingJob,
                'created' => false,
            ];
        }

        if ($this->access->activeApiJobsCount($customer) >= $this->access->allowedConcurrentJobs($customer)) {
            throw new \RuntimeException('You reached your concurrent API job limit for the current plan.');
        }

        $text = trim((string) $validated['text']);
        $chars = mb_strlen($text);
        $estimatedCredits = max(0, (int) $customer->priceCreditsFor($actionCode, [
            'chars' => $chars,
            'language' => (string) $validated['language'],
            'speaker_id' => (string) $validated['speaker_id'],
            'channel' => \App\Models\PricingRule::CHANNEL_API,
        ]));

        if ($estimatedCredits <= 0) {
            throw new \RuntimeException('Pricing is not configured for this API action.');
        }

        [$tool, $action] = $this->resolveToolAndAction($toolCode, $actionCode);

        $apiJobId = 'job_'.Str::lower((string) Str::ulid());
        $mlJobId = (string) Str::uuid();
        $expiresAt = $storageMode === 'temporary'
            ? now()->addDays((int) config('customer_api.temporary_file_ttl_days', 7))
            : null;

        $context = [
            'ref_audio' => $engine === 'xomni'
                ? trim((string) data_get(
                    $this->voices->findVoiceForCustomer($customer, $engine, (string) $validated['speaker_id'])?->meta,
                    'ref_audio',
                    ''
                ))
                : null,
        ];

        if ($engine === 'xomni' && trim((string) ($context['ref_audio'] ?? '')) === '') {
            throw new \RuntimeException('The selected Omni voice does not have a reference audio file.');
        }

        $providerInput = $providerPayload($validated, $context);
        $reservation = $this->reservations->reserve((int) $customer->id, $apiJobId, $estimatedCredits, [
            'source_type' => 'public_api',
            'tool_code' => $toolCode,
            'tool_action' => $actionCode,
            'metric_code' => 'character',
            'metric_quantity' => $chars,
            'api_key_id' => (int) $apiKey->id,
        ]);

        try {
            DB::transaction(function () use (
                $apiJobId,
                $mlJobId,
                $customer,
                $apiKey,
                $tool,
                $action,
                $actionCode,
                $toolCode,
                $engine,
                $storageMode,
                $estimatedCredits,
                $requestHash,
                $usageLog,
                $validated,
                $providerInput,
                $expiresAt
            ): void {
                MlJob::create([
                    'id' => $mlJobId,
                    'customer_id' => (int) $customer->id,
                    'tool_id' => (int) $tool->id,
                    'tool_action_id' => (int) $action->id,
                    'job_kind' => $toolCode,
                    'status' => 'queued',
                    'provider' => 'runpod',
                    'input_hash' => $requestHash,
                    'input' => array_merge($providerInput, [
                        'text' => $text = trim((string) $validated['text']),
                        'speaker_id' => (string) $validated['speaker_id'],
                        'language' => (string) $validated['language'],
                        'split' => (bool) $validated['split'],
                        'max_words' => (int) $validated['max_words'],
                        'fade_ms' => (int) $validated['fade_ms'],
                        'temperature' => (float) $validated['temperature'],
                        'top_k' => (int) $validated['top_k'],
                        'top_p' => (float) $validated['top_p'],
                        'repetition_penalty' => (float) $validated['repetition_penalty'],
                        'length_penalty' => (float) $validated['length_penalty'],
                        'speed' => (float) $validated['speed'],
                        'api_job_id' => $apiJobId,
                        'api_storage_mode' => $storageMode,
                        'api_expires_at' => $expiresAt?->toIso8601String(),
                    ]),
                    'credits_charged' => $estimatedCredits,
                    'started_at' => now(),
                ]);

                ApiJob::create([
                    'id' => $apiJobId,
                    'customer_id' => (int) $customer->id,
                    'api_key_id' => (int) $apiKey->id,
                    'ml_job_id' => $mlJobId,
                    'tool_code' => $toolCode,
                    'tool_action' => $actionCode,
                    'engine' => $engine,
                    'status' => 'queued',
                    'input_hash' => $requestHash,
                    'estimated_credits' => $estimatedCredits,
                    'reserved_credits' => $estimatedCredits,
                    'final_credits' => 0,
                    'storage_mode' => $storageMode,
                    'started_at' => now(),
                    'meta' => [
                        'request_payload' => $validated,
                    ],
                ]);

                $usageLog->forceFill([
                    'api_job_id' => $apiJobId,
                    'metric_code' => 'character',
                    'metric_quantity' => $chars = mb_strlen(trim((string) $validated['text'])),
                    'status' => 'accepted',
                ])->save();
            }, 3);

            $endpointId = (string) (data_get($tool->meta, 'runpod_endpoint_id') ?: config($endpointConfig));

            if ($endpointId === '') {
                throw new \RuntimeException('RunPod endpoint is not configured.');
            }

            $response = $this->runpod->run(
                $endpointId,
                $providerInput,
                (int) (data_get($tool->meta, 'runpod_timeout') ?: config('runpod.timeout', 60))
            );

            $providerJobId = (string) data_get($response, 'id', '');

            if ($providerJobId === '') {
                throw new \RuntimeException('RunPod did not return a job ID.');
            }

            MlJob::query()->where('id', $mlJobId)->update([
                'status' => 'running',
                'provider_job_id' => $providerJobId,
                'updated_at' => now(),
            ]);

            ApiJob::query()->where('id', $apiJobId)->update([
                'status' => 'queued',
                'updated_at' => now(),
            ]);

            $usageLog->forceFill([
                'status' => 'queued',
            ])->save();

            return [
                'api_job' => ApiJob::query()->with(['mlJob.tool', 'resultFiles.storageFile'])->findOrFail($apiJobId),
                'created' => true,
            ];
        } catch (\Throwable $e) {
            $this->reservations->release($reservation);

            ApiJob::query()->where('id', $apiJobId)->update([
                'status' => 'failed',
                'error_code' => 'provider_start_failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

            MlJob::query()->where('id', $mlJobId)->update([
                'status' => 'failed',
                'error' => ['message' => $e->getMessage()],
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            $usageLog->forceFill([
                'status' => 'failed',
                'credits_charged' => 0,
            ])->save();

            throw $e;
        }
    }

    protected function claimIdempotencyKey(
        Request $request,
        Customer $customer,
        CustomerApiKey $apiKey,
        string $idempotencyKey,
        string $requestHash
    ): ApiUsageLog {
        if ($idempotencyKey === '') {
            return ApiUsageLog::create([
                'customer_id' => (int) $customer->id,
                'api_key_id' => (int) $apiKey->id,
                'endpoint' => (string) $request->path(),
                'method' => (string) $request->method(),
                'status' => 'received',
                'response_code' => 202,
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
                'request_hash' => $requestHash,
            ]);
        }

        try {
            return ApiUsageLog::create([
                'customer_id' => (int) $customer->id,
                'api_key_id' => (int) $apiKey->id,
                'endpoint' => (string) $request->path(),
                'method' => (string) $request->method(),
                'status' => 'received',
                'response_code' => 202,
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
            ]);
        } catch (QueryException $e) {
            $existing = ApiUsageLog::query()
                ->where('customer_id', (int) $customer->id)
                ->where('api_key_id', (int) $apiKey->id)
                ->where('endpoint', (string) $request->path())
                ->where('method', (string) $request->method())
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if (! $existing instanceof ApiUsageLog) {
                throw $e;
            }

            if ((string) $existing->request_hash !== $requestHash) {
                throw new \RuntimeException('The provided Idempotency-Key was already used for a different request.');
            }

            return $existing;
        }
    }

    /**
     * @return array{0: Tool, 1: ToolAction}
     */
    protected function resolveToolAndAction(string $toolCode, string $actionCode): array
    {
        $tool = Tool::query()->where('code', $toolCode)->first();
        $action = ToolAction::query()->where('full_code', $actionCode)->first();

        if (! $tool instanceof Tool || ! $action instanceof ToolAction) {
            throw new \RuntimeException('Tool configuration is missing.');
        }

        return [$tool, $action];
    }
}
