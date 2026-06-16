<?php

namespace App\Services\CustomerApi;

use App\Models\ApiJob;
use App\Models\ApiUsageLog;
use App\Models\Customer;
use App\Models\CustomerApiKey;
use App\Models\MlJob;
use App\Models\PlanEntitlement;
use App\Models\PricingRule;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Services\Media\AudioProbeService;
use App\Services\OCR\OcrJobSyncService;
use App\Services\Providers\RunPodProvider;
use App\Services\Security\JobExecutionLockService;
use App\Services\STEM\StemJobSyncService;
use App\Services\Storage\CustomerOutputStorage;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CustomerApiJobSubmissionService
{
    protected const AUDIO_FILE_RULE = 'required|file|mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,audio/aac,audio/ogg,audio/webm,audio/flac,audio/x-flac|max:102400';

    protected const CLONE_AUDIO_FILE_RULE = 'required|file|mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,audio/aac,audio/ogg,audio/webm|max:20480';

    protected const TTS_PRODUCTS = [
        'apollo-1-0v' => [
            'scope' => 'tts:apollo-1-0v',
            'engine' => 'xtts',
            'tool_code' => 'tts',
            'job_kind' => 'tts',
            'action_code' => 'tts.standard',
            'language_default' => 'ar',
            'endpoint_config' => 'runpod.endpoints.xtts',
            'type' => 'voice',
        ],
        'apollo-1-5v' => [
            'scope' => 'tts:apollo-1-5v',
            'engine' => 'xomni',
            'tool_code' => 'xomni',
            'job_kind' => 'xomni',
            'action_code' => 'xomni.generate',
            'language_default' => 'ckb',
            'endpoint_config' => 'runpod.endpoints.omni',
            'type' => 'voice',
        ],
        'delta-1-0v' => [
            'scope' => 'tts:delta-1-0v',
            'engine' => 'ftts',
            'tool_code' => 'ftts',
            'job_kind' => 'ftts',
            'action_code' => 'ftts.standard',
            'language_default' => 'ckb',
            'endpoint_config' => 'runpod.endpoints.ftts',
            'type' => 'voice',
        ],
        'vector-1-0' => [
            'scope' => 'tts:vector-1-0',
            'engine' => 'clone_tts',
            'tool_code' => 'clone_tts',
            'job_kind' => 'clone_tts',
            'action_code' => 'clone_tts.standard',
            'language_default' => 'ar',
            'endpoint_config' => 'runpod.endpoints.xtts',
            'type' => 'clone',
        ],
        'vector-1-5' => [
            'scope' => 'tts:vector-1-5',
            'engine' => 'clone_xomni',
            'tool_code' => 'clone_xomni',
            'job_kind' => 'clone_xomni',
            'action_code' => 'clone_xomni.generate',
            'language_default' => 'ckb',
            'endpoint_config' => 'runpod.endpoints.omni',
            'type' => 'clone',
        ],
    ];

    public function __construct(
        protected CustomerApiAccessService $access,
        protected CustomerApiScopeService $scopes,
        protected CustomerApiVoiceCatalog $voices,
        protected CustomerApiCreditReservationService $reservations,
        protected RunPodProvider $runpod,
        protected CustomerOutputStorage $storage,
        protected AudioProbeService $audioProbe,
        protected JobExecutionLockService $locks,
        protected StemJobSyncService $stemSync,
        protected OcrJobSyncService $ocrSync,
    ) {}

    /**
     * @return array{api_job: ApiJob, created: bool}
     */
    public function submitTtsProduct(Request $request, Customer $customer, CustomerApiKey $apiKey, string $product): array
    {
        $product = strtolower(trim($product));
        $config = self::TTS_PRODUCTS[$product] ?? null;

        if ($config === null) {
            throw new \RuntimeException('Unknown TTS product.');
        }

        return $config['type'] === 'clone'
            ? $this->submitCloneProduct($request, $customer, $apiKey, $config)
            : $this->submitVoiceProduct($request, $customer, $apiKey, $config);
    }

    /**
     * @return array{api_job: ApiJob, created: bool}
     */
    public function submitWasr(Request $request, Customer $customer, CustomerApiKey $apiKey): array
    {
        $scope = 'asr:wasr';
        $actionCode = 'asr.standard';

        $this->authorizeScopeAndTool($customer, $apiKey, $scope, $actionCode);

        $data = array_merge([
            'language' => 'ckb',
            'chunkLengthS' => 30,
            'strideLeftS' => 5,
            'strideRightS' => 5,
            'beamSize' => 5,
            'storage' => ['mode' => 'temporary'],
        ], $request->all());
        $data['audioFile'] = $request->file('audioFile');

        $validated = Validator::make($data, [
            'audioFile' => self::AUDIO_FILE_RULE,
            'language' => ['required', 'string', 'in:ckb,ar,en'],
            'chunkLengthS' => ['required', 'integer', 'min:5', 'max:120'],
            'strideLeftS' => ['required', 'integer', 'min:0', 'max:30'],
            'strideRightS' => ['required', 'integer', 'min:0', 'max:30'],
            'beamSize' => ['required', 'integer', 'min:1', 'max:20'],
            'storage.mode' => ['nullable', 'string', 'in:temporary,permanent'],
        ])->validate();

        $storageMode = (string) data_get($validated, 'storage.mode', 'temporary');
        $expiresAt = $this->storageExpiresAt($storageMode);
        $audioFile = $validated['audioFile'];
        $audioInfo = $this->probeAudioFile($audioFile);

        if ((int) $audioInfo['billable_min'] <= 0) {
            throw new \RuntimeException('Could not calculate billing for this file.');
        }

        $audioHash = $this->uploadedFileHash($audioFile);
        $requestHash = $this->requestHash([
            'tool' => 'wasr',
            'action' => $actionCode,
            'payload' => [
                'language' => (string) $validated['language'],
                'chunkLengthS' => (int) $validated['chunkLengthS'],
                'strideLeftS' => (int) $validated['strideLeftS'],
                'strideRightS' => (int) $validated['strideRightS'],
                'beamSize' => (int) $validated['beamSize'],
                'audio_hash' => $audioHash,
                'storage_mode' => $storageMode,
            ],
        ]);

        $usageLog = $this->claimIdempotencyKey(
            $request,
            $customer,
            $apiKey,
            trim((string) $request->header('Idempotency-Key', '')),
            $requestHash
        );

        if ($usageLog->api_job_id) {
            return $this->existingJobResponse((string) $usageLog->api_job_id);
        }

        $estimatedCredits = max(0, (int) $customer->priceCreditsFor($actionCode, [
            'minutes' => (int) $audioInfo['billable_min'],
            'metric_code' => 'minute',
            'language' => (string) $validated['language'],
            'channel' => PricingRule::CHANNEL_API,
        ]));

        if ($estimatedCredits <= 0) {
            throw new \RuntimeException('Pricing is not configured for this API action.');
        }

        [$tool, $action] = $this->resolveToolAndActionWithFallback(['wasr', 'asr'], $actionCode);
        $apiJobId = 'job_'.Str::lower((string) Str::ulid());
        $mlJobId = (string) Str::uuid();
        $reservation = $this->reservations->reserve((int) $customer->id, $apiJobId, $estimatedCredits, [
            'source_type' => 'public_api',
            'tool_code' => 'wasr',
            'tool_action' => $actionCode,
            'metric_code' => 'minute',
            'metric_quantity' => (int) $audioInfo['billable_min'],
            'api_key_id' => (int) $apiKey->id,
        ]);

        $savedAudio = null;

        try {
            $audioExt = strtolower((string) ($audioInfo['audio_ext'] ?? $audioFile->getClientOriginalExtension() ?: 'wav'));
            $audioKey = $this->storage->inputPath($customer, 'wasr', $mlJobId, 'input', $audioExt);

            DB::transaction(function () use ($mlJobId, $apiJobId, $customer, $apiKey, $tool, $action, $actionCode, $estimatedCredits, $requestHash, $usageLog, $validated, $audioInfo, $audioFile, $audioHash, $storageMode, $expiresAt): void {
                MlJob::create([
                    'id' => $mlJobId,
                    'customer_id' => (int) $customer->id,
                    'tool_id' => (int) $tool->id,
                    'tool_action_id' => (int) $action->id,
                    'job_kind' => 'wasr',
                    'status' => 'queued',
                    'provider' => 'runpod',
                    'input_hash' => $audioHash,
                    'credits_charged' => $estimatedCredits,
                    'input' => [
                        'lang' => (string) $validated['language'],
                        'chunk_length_s' => (int) $validated['chunkLengthS'],
                        'stride_left_s' => (int) $validated['strideLeftS'],
                        'stride_right_s' => (int) $validated['strideRightS'],
                        'beam_size' => (int) $validated['beamSize'],
                        'audio_name' => (string) $audioFile->getClientOriginalName(),
                        'audio_mime' => (string) ($audioFile->getMimeType() ?: 'audio/*'),
                        'audio_bytes' => (int) ($audioFile->getSize() ?? 0),
                        'audio_duration_sec' => (float) $audioInfo['duration_sec'],
                        'audio_duration_min' => (float) $audioInfo['duration_min'],
                        'audio_billable_min' => (int) $audioInfo['billable_min'],
                        'api_job_id' => $apiJobId,
                        'api_storage_mode' => $storageMode,
                        'api_expires_at' => $expiresAt?->toIso8601String(),
                    ],
                    'started_at' => now(),
                    'storage_in_bytes' => 0,
                    'storage_out_bytes' => 0,
                ]);

                ApiJob::create([
                    'id' => $apiJobId,
                    'customer_id' => (int) $customer->id,
                    'api_key_id' => (int) $apiKey->id,
                    'ml_job_id' => $mlJobId,
                    'tool_code' => 'wasr',
                    'tool_action' => $actionCode,
                    'engine' => 'wasr',
                    'status' => 'queued',
                    'input_hash' => $requestHash,
                    'estimated_credits' => $estimatedCredits,
                    'reserved_credits' => $estimatedCredits,
                    'final_credits' => 0,
                    'storage_mode' => $storageMode,
                    'started_at' => now(),
                    'meta' => [
                        'request_payload' => [
                            'language' => (string) $validated['language'],
                            'chunkLengthS' => (int) $validated['chunkLengthS'],
                            'strideLeftS' => (int) $validated['strideLeftS'],
                            'strideRightS' => (int) $validated['strideRightS'],
                            'beamSize' => (int) $validated['beamSize'],
                        ],
                    ],
                ]);

                $usageLog->forceFill([
                    'api_job_id' => $apiJobId,
                    'metric_code' => 'minute',
                    'metric_quantity' => (int) $audioInfo['billable_min'],
                    'status' => 'accepted',
                ])->save();
            }, 3);

            $savedAudio = $this->storage->saveUploadedFileToS3((int) $customer->id, $audioFile, $audioKey, [
                'job_id' => $mlJobId,
                'tool' => 'wasr',
                'purpose' => 'input_audio',
                'role' => 'source_audio',
                'checksum' => $audioHash,
                'original_name' => $audioFile->getClientOriginalName(),
            ]);

            $audioUrl = $this->storage->temporaryUrl((string) $savedAudio['path'], 120, [
                'ResponseContentType' => $savedAudio['mime'] ?? ($audioFile->getMimeType() ?: 'audio/*'),
            ]);

            MlJob::query()->where('id', $mlJobId)->update([
                'input' => array_merge((array) (MlJob::find($mlJobId)?->input ?? []), [
                    'audio_disk' => (string) $savedAudio['disk'],
                    'audio_path' => (string) $savedAudio['path'],
                    'audio_url' => $audioUrl,
                    'audio_ext' => $audioExt,
                ]),
                'storage_in_bytes' => (int) $savedAudio['bytes'],
            ]);

            $lock = $this->locks->acquireAsrLock(
                customerId: (int) $customer->id,
                jobId: $mlJobId,
                inputHash: $audioHash,
                session: $this->tokenOwnerId($request),
                agent: $request->userAgent(),
                ip: $request->ip(),
                jobKind: 'wasr',
            );

            if (! ($lock['ok'] ?? false)) {
                throw new \RuntimeException((string) ($lock['message'] ?? 'Could not acquire ASR lock.'));
            }

            $response = $this->runpod->run(
                (string) (data_get($tool->meta, 'runpod_endpoint_id') ?: config('runpod.endpoints.wasr') ?: env('RUNPOD_ENDPOINT_ID_WASR')),
                [
                    'audio_url' => $audioUrl,
                    'lang' => (string) $validated['language'],
                    'chunk_length_s' => (int) $validated['chunkLengthS'],
                    'stride_left_s' => (int) $validated['strideLeftS'],
                    'stride_right_s' => (int) $validated['strideRightS'],
                    'beam_size' => (int) $validated['beamSize'],
                ],
                (int) (data_get($tool->meta, 'runpod_timeout') ?: config('runpod.timeout', 60))
            );

            return $this->markStartedJob($usageLog, $apiJobId, $mlJobId, (string) data_get($response, 'id', ''));
        } catch (\Throwable $e) {
            $this->cleanupSavedInput($customer, $savedAudio, (int) ($audioFile->getSize() ?? 0));

            return $this->handleStartFailure($reservation, $usageLog, $apiJobId, $mlJobId, $e);
        }
    }

    /**
     * @return array{api_job: ApiJob, created: bool}
     */
    public function submitQasr(Request $request, Customer $customer, CustomerApiKey $apiKey): array
    {
        return $this->submitQasrLike($request, $customer, $apiKey, [
            'scope' => 'asr:qasr',
            'tool_code' => 'qasr',
            'job_kind' => 'qasr',
            'action_code' => 'qasr.standard',
            'type' => 'asr',
            'message' => 'QASR job started.',
        ]);
    }

    /**
     * @return array{api_job: ApiJob, created: bool}
     */
    public function submitCaption(Request $request, Customer $customer, CustomerApiKey $apiKey): array
    {
        return $this->submitQasrLike($request, $customer, $apiKey, [
            'scope' => 'caption:qasr',
            'tool_code' => 'caption',
            'job_kind' => 'caption',
            'action_code' => 'caption.standard',
            'type' => 'caption',
            'message' => 'Caption job started.',
        ]);
    }

    /**
     * @return array{api_job: ApiJob, created: bool}
     */
    public function submitOcr(Request $request, Customer $customer, CustomerApiKey $apiKey): array
    {
        $scope = 'ocr:generate';
        $actionCode = 'ocr.standard';

        $this->authorizeScopeAndTool($customer, $apiKey, $scope, $actionCode);

        $data = array_merge([
            'lang' => 'ckb+ara+eng',
            'pageRange' => '',
            'dpi' => 200,
            'psm' => 6,
            'oem' => 3,
            'normalize' => false,
            'grayscale' => true,
            'autocontrast' => true,
            'sharpen' => true,
            'binarize' => false,
            'clientPdfPageCount' => null,
            'storage' => ['mode' => 'temporary'],
        ], $request->all());
        $data['documentFile'] = $request->file('documentFile');

        $validated = Validator::make($data, [
            'documentFile' => ['required', 'file', 'mimes:pdf', 'max:204800'],
            'lang' => ['required', 'string', 'max:50'],
            'pageRange' => ['nullable', 'string', 'max:255'],
            'dpi' => ['required', 'integer', 'min:72', 'max:600'],
            'psm' => ['required', 'integer', 'min:0', 'max:13'],
            'oem' => ['required', 'integer', 'min:0', 'max:3'],
            'normalize' => ['boolean'],
            'grayscale' => ['boolean'],
            'autocontrast' => ['boolean'],
            'sharpen' => ['boolean'],
            'binarize' => ['boolean'],
            'clientPdfPageCount' => ['nullable', 'integer', 'min:1'],
            'storage.mode' => ['nullable', 'string', 'in:temporary,permanent'],
        ])->validate();

        $storageMode = (string) data_get($validated, 'storage.mode', 'temporary');
        $expiresAt = $this->storageExpiresAt($storageMode);
        $documentFile = $validated['documentFile'];
        $documentHash = $this->uploadedFileHash($documentFile);
        $pages = $this->estimatedOcrPages((int) ($validated['clientPdfPageCount'] ?: 1), (string) ($validated['pageRange'] ?? ''));

        $requestHash = $this->requestHash([
            'tool' => 'ocr',
            'action' => $actionCode,
            'payload' => [
                'lang' => (string) $validated['lang'],
                'pageRange' => trim((string) ($validated['pageRange'] ?? '')),
                'dpi' => (int) $validated['dpi'],
                'psm' => (int) $validated['psm'],
                'oem' => (int) $validated['oem'],
                'normalize' => (bool) $validated['normalize'],
                'grayscale' => (bool) $validated['grayscale'],
                'autocontrast' => (bool) $validated['autocontrast'],
                'sharpen' => (bool) $validated['sharpen'],
                'binarize' => (bool) $validated['binarize'],
                'document_hash' => $documentHash,
                'storage_mode' => $storageMode,
            ],
        ]);

        $usageLog = $this->claimIdempotencyKey($request, $customer, $apiKey, trim((string) $request->header('Idempotency-Key', '')), $requestHash);

        if ($usageLog->api_job_id) {
            return $this->existingJobResponse((string) $usageLog->api_job_id);
        }

        $estimatedCredits = max(0, (int) $customer->priceCreditsFor($actionCode, [
            'pages' => $pages,
            'lang' => (string) $validated['lang'],
            'dpi' => (int) $validated['dpi'],
            'channel' => PricingRule::CHANNEL_API,
        ]));

        if ($estimatedCredits <= 0) {
            throw new \RuntimeException('Pricing is not configured for this API action.');
        }

        [$tool, $action] = $this->resolveToolAndAction('ocr', $actionCode);
        $apiJobId = 'job_'.Str::lower((string) Str::ulid());
        $mlJobId = (string) Str::uuid();
        $reservation = $this->reservations->reserve((int) $customer->id, $apiJobId, $estimatedCredits, [
            'source_type' => 'public_api',
            'tool_code' => 'ocr',
            'tool_action' => $actionCode,
            'metric_code' => 'page',
            'metric_quantity' => $pages,
            'api_key_id' => (int) $apiKey->id,
        ]);

        $savedInput = null;

        try {
            $documentExt = strtolower((string) ($documentFile->getClientOriginalExtension() ?: 'pdf'));
            $inputPath = $this->storage->inputPath($customer, 'ocr', $mlJobId, 'input', $documentExt);

            DB::transaction(function () use ($mlJobId, $apiJobId, $customer, $apiKey, $tool, $action, $actionCode, $estimatedCredits, $requestHash, $usageLog, $validated, $documentFile, $documentHash, $documentExt, $pages, $storageMode, $expiresAt): void {
                MlJob::create([
                    'id' => $mlJobId,
                    'customer_id' => (int) $customer->id,
                    'tool_id' => (int) $tool->id,
                    'tool_action_id' => (int) $action->id,
                    'job_kind' => 'ocr',
                    'status' => 'queued',
                    'provider' => 'runpod',
                    'provider_job_id' => null,
                    'input_hash' => $documentHash,
                    'credits_charged' => $estimatedCredits,
                    'input' => [
                        'file_name' => (string) $documentFile->getClientOriginalName(),
                        'file_mime' => (string) ($documentFile->getMimeType() ?: 'application/pdf'),
                        'file_bytes' => (int) ($documentFile->getSize() ?? 0),
                        'file_ext' => $documentExt,
                        'lang' => (string) $validated['lang'],
                        'page_range' => trim((string) ($validated['pageRange'] ?? '')),
                        'pages_estimated' => $pages,
                        'client_pdf_page_count' => (int) ($validated['clientPdfPageCount'] ?: 0),
                        'dpi' => (int) $validated['dpi'],
                        'psm' => (int) $validated['psm'],
                        'oem' => (int) $validated['oem'],
                        'normalize' => (bool) $validated['normalize'],
                        'grayscale' => (bool) $validated['grayscale'],
                        'autocontrast' => (bool) $validated['autocontrast'],
                        'sharpen' => (bool) $validated['sharpen'],
                        'binarize' => (bool) $validated['binarize'],
                        'api_job_id' => $apiJobId,
                        'api_storage_mode' => $storageMode,
                        'api_expires_at' => $expiresAt?->toIso8601String(),
                    ],
                    'started_at' => now(),
                    'storage_in_bytes' => 0,
                    'storage_out_bytes' => 0,
                    'meta' => [
                        'billing_metric' => 'ocr_page',
                        'billing_action' => 'standard',
                    ],
                ]);

                ApiJob::create([
                    'id' => $apiJobId,
                    'customer_id' => (int) $customer->id,
                    'api_key_id' => (int) $apiKey->id,
                    'ml_job_id' => $mlJobId,
                    'tool_code' => 'ocr',
                    'tool_action' => $actionCode,
                    'engine' => 'ocr',
                    'status' => 'queued',
                    'input_hash' => $requestHash,
                    'estimated_credits' => $estimatedCredits,
                    'reserved_credits' => $estimatedCredits,
                    'final_credits' => 0,
                    'storage_mode' => $storageMode,
                    'started_at' => now(),
                    'meta' => [
                        'request_payload' => [
                            'lang' => (string) $validated['lang'],
                            'pageRange' => trim((string) ($validated['pageRange'] ?? '')),
                            'dpi' => (int) $validated['dpi'],
                            'psm' => (int) $validated['psm'],
                            'oem' => (int) $validated['oem'],
                            'normalize' => (bool) $validated['normalize'],
                            'grayscale' => (bool) $validated['grayscale'],
                            'autocontrast' => (bool) $validated['autocontrast'],
                            'sharpen' => (bool) $validated['sharpen'],
                            'binarize' => (bool) $validated['binarize'],
                        ],
                    ],
                ]);

                $usageLog->forceFill([
                    'api_job_id' => $apiJobId,
                    'metric_code' => 'page',
                    'metric_quantity' => $pages,
                    'status' => 'accepted',
                ])->save();
            }, 3);

            $savedInput = $this->storage->saveUploadedFileToS3((int) $customer->id, $documentFile, $inputPath, [
                'job_id' => $mlJobId,
                'tool' => 'ocr',
                'purpose' => 'input_document',
                'role' => 'source_pdf',
                'checksum' => $documentHash,
                'original_name' => $documentFile->getClientOriginalName(),
            ]);

            $job = MlJob::query()->findOrFail($mlJobId);
            $job->update([
                'input' => array_merge((array) ($job->input ?? []), [
                    'file_disk' => (string) $savedInput['disk'],
                    'file_path' => (string) $savedInput['path'],
                    'file_url' => $this->storage->temporaryUrl((string) $savedInput['path'], 120, [
                        'ResponseContentType' => $savedInput['mime'] ?? 'application/pdf',
                    ]),
                ]),
                'storage_in_bytes' => (int) $savedInput['bytes'],
            ]);

            $lock = $this->locks->acquireOcrLock(
                customerId: (int) $customer->id,
                jobId: $mlJobId,
                inputHash: $documentHash,
                session: $this->tokenOwnerId($request),
                agent: $request->userAgent(),
                ip: $request->ip(),
            );

            if (! ($lock['ok'] ?? false)) {
                throw new \RuntimeException((string) ($lock['message'] ?? 'Could not lock the OCR job.'));
            }

            $response = $this->runpod->run(
                (string) (config('runpod.endpoints.kocr') ?: env('RUNPOD_ENDPOINT_ID_KOCR')),
                $this->ocrSync->buildRunpodInput(
                    job: $job->fresh(),
                    inputDisk: (string) $savedInput['disk'],
                    inputPath: (string) $savedInput['path'],
                    fileName: (string) ($documentFile->getClientOriginalName() ?: 'input.pdf'),
                    lang: (string) $validated['lang'],
                    pageRange: trim((string) ($validated['pageRange'] ?? '')),
                    dpi: (int) $validated['dpi'],
                    psm: (int) $validated['psm'],
                    oem: (int) $validated['oem'],
                    normalize: (bool) $validated['normalize'],
                    grayscale: (bool) $validated['grayscale'],
                    autocontrast: (bool) $validated['autocontrast'],
                    sharpen: (bool) $validated['sharpen'],
                    binarize: (bool) $validated['binarize'],
                )
            );

            return $this->markStartedJob($usageLog, $apiJobId, $mlJobId, (string) data_get($response, 'id', ''));
        } catch (\Throwable $e) {
            $this->cleanupSavedInput($customer, $savedInput, (int) ($documentFile->getSize() ?? 0));

            return $this->handleStartFailure($reservation, $usageLog, $apiJobId, $mlJobId, $e);
        }
    }

    /**
     * @return array{api_job: ApiJob, created: bool}
     */
    public function submitTranslate(Request $request, Customer $customer, CustomerApiKey $apiKey): array
    {
        $scope = 'translation:generate';
        $actionCode = 'tran.standard';

        $this->authorizeScopeAndTool($customer, $apiKey, $scope, $actionCode);

        $data = array_merge([
            'sourceLang' => 'ku',
            'targetLang' => 'en',
            'maxNewTokens' => 256,
            'chunkChars' => 1200,
            'storage' => ['mode' => 'temporary'],
        ], $request->all());

        $validated = Validator::make($data, [
            'text' => ['required', 'string', 'min:1', 'max:2400'],
            'sourceLang' => ['required', 'string', 'in:'.implode(',', array_keys($this->translationLanguageCatalog()))],
            'targetLang' => ['required', 'string', 'in:'.implode(',', array_keys($this->translationLanguageCatalog())), 'different:sourceLang'],
            'maxNewTokens' => ['required', 'integer', 'min:64', 'max:2048'],
            'chunkChars' => ['required', 'integer', 'min:200', 'max:5000'],
            'storage.mode' => ['nullable', 'string', 'in:temporary,permanent'],
        ])->validate();

        $storageMode = (string) data_get($validated, 'storage.mode', 'temporary');
        $expiresAt = $this->storageExpiresAt($storageMode);
        $text = trim((string) $validated['text']);
        $chars = mb_strlen($text);

        $requestHash = $this->requestHash([
            'tool' => 'tran',
            'action' => $actionCode,
            'payload' => [
                'text' => $text,
                'sourceLang' => (string) $validated['sourceLang'],
                'targetLang' => (string) $validated['targetLang'],
                'maxNewTokens' => (int) $validated['maxNewTokens'],
                'chunkChars' => (int) $validated['chunkChars'],
                'storage_mode' => $storageMode,
            ],
        ]);

        $usageLog = $this->claimIdempotencyKey($request, $customer, $apiKey, trim((string) $request->header('Idempotency-Key', '')), $requestHash);

        if ($usageLog->api_job_id) {
            return $this->existingJobResponse((string) $usageLog->api_job_id);
        }

        $estimatedCredits = max(0, (int) $customer->priceCreditsFor($actionCode, [
            'chars' => $chars,
            'metric_code' => 'character',
            'source_lang' => (string) $validated['sourceLang'],
            'target_lang' => (string) $validated['targetLang'],
            'channel' => PricingRule::CHANNEL_API,
        ]));

        if ($estimatedCredits <= 0) {
            throw new \RuntimeException('Pricing is not configured for this API action.');
        }

        [$tool, $action] = $this->resolveToolAndAction('tran', $actionCode);
        $apiJobId = 'job_'.Str::lower((string) Str::ulid());
        $mlJobId = (string) Str::uuid();
        $reservation = $this->reservations->reserve((int) $customer->id, $apiJobId, $estimatedCredits, [
            'source_type' => 'public_api',
            'tool_code' => 'tran',
            'tool_action' => $actionCode,
            'metric_code' => 'character',
            'metric_quantity' => $chars,
            'api_key_id' => (int) $apiKey->id,
        ]);

        $savedSource = null;

        try {
            DB::transaction(function () use ($mlJobId, $apiJobId, $customer, $apiKey, $tool, $action, $actionCode, $estimatedCredits, $requestHash, $usageLog, $validated, $text, $chars, $storageMode, $expiresAt): void {
                MlJob::create([
                    'id' => $mlJobId,
                    'customer_id' => (int) $customer->id,
                    'tool_id' => (int) $tool->id,
                    'tool_action_id' => (int) $action->id,
                    'job_kind' => 'tran',
                    'status' => 'queued',
                    'provider' => 'runpod',
                    'provider_job_id' => null,
                    'input' => [
                        'text' => $text,
                        'source_lang' => (string) $validated['sourceLang'],
                        'target_lang' => (string) $validated['targetLang'],
                        'max_new_tokens' => (int) $validated['maxNewTokens'],
                        'chunk_chars' => (int) $validated['chunkChars'],
                        'source_char_count' => $chars,
                        'api_job_id' => $apiJobId,
                        'api_storage_mode' => $storageMode,
                        'api_expires_at' => $expiresAt?->toIso8601String(),
                    ],
                    'credits_charged' => $estimatedCredits,
                    'started_at' => now(),
                    'storage_in_bytes' => 0,
                    'storage_out_bytes' => 0,
                ]);

                ApiJob::create([
                    'id' => $apiJobId,
                    'customer_id' => (int) $customer->id,
                    'api_key_id' => (int) $apiKey->id,
                    'ml_job_id' => $mlJobId,
                    'tool_code' => 'tran',
                    'tool_action' => $actionCode,
                    'engine' => 'tran',
                    'status' => 'queued',
                    'input_hash' => $requestHash,
                    'estimated_credits' => $estimatedCredits,
                    'reserved_credits' => $estimatedCredits,
                    'final_credits' => 0,
                    'storage_mode' => $storageMode,
                    'started_at' => now(),
                    'meta' => [
                        'request_payload' => [
                            'sourceLang' => (string) $validated['sourceLang'],
                            'targetLang' => (string) $validated['targetLang'],
                            'maxNewTokens' => (int) $validated['maxNewTokens'],
                            'chunkChars' => (int) $validated['chunkChars'],
                        ],
                    ],
                ]);

                $usageLog->forceFill([
                    'api_job_id' => $apiJobId,
                    'metric_code' => 'character',
                    'metric_quantity' => $chars,
                    'status' => 'accepted',
                ])->save();
            }, 3);

            $sourceKey = $this->storage->inputPath($customer, 'tran', $mlJobId, 'source', 'txt');
            $savedSource = $this->storage->saveTextToS3((int) $customer->id, $sourceKey, $text, [
                'job_id' => $mlJobId,
                'tool' => 'tran',
                'purpose' => 'source_text',
                'mime' => 'text/plain; charset=UTF-8',
            ]);

            MlJob::query()->where('id', $mlJobId)->update([
                'input' => array_merge((array) (MlJob::find($mlJobId)?->input ?? []), [
                    'source_disk' => (string) $savedSource['disk'],
                    'source_path' => (string) $savedSource['path'],
                    'source_bytes' => (int) $savedSource['bytes'],
                ]),
                'storage_in_bytes' => (int) $savedSource['bytes'],
            ]);

            $response = $this->runpod->run(
                (string) (data_get($tool->meta, 'runpod_endpoint_id') ?: config('runpod.endpoints.tran') ?: env('RUNPOD_ENDPOINT_ID_TRAN')),
                [
                    'text' => $text,
                    'source_lang' => (string) $validated['sourceLang'],
                    'target_lang' => (string) $validated['targetLang'],
                    'max_new_tokens' => (int) $validated['maxNewTokens'],
                    'chunk_chars' => (int) $validated['chunkChars'],
                ],
                (int) (data_get($tool->meta, 'runpod_timeout') ?: config('runpod.timeout', 60))
            );

            return $this->markStartedJob($usageLog, $apiJobId, $mlJobId, (string) data_get($response, 'id', ''));
        } catch (\Throwable $e) {
            $this->cleanupSavedInput($customer, $savedSource, strlen($text));

            return $this->handleStartFailure($reservation, $usageLog, $apiJobId, $mlJobId, $e);
        }
    }

    /**
     * @return array{api_job: ApiJob, created: bool}
     */
    public function submitStem(Request $request, Customer $customer, CustomerApiKey $apiKey): array
    {
        $scope = 'stem:generate';
        $actionCode = 'stem.'.((int) $request->input('stems', 4) === 2 ? 'sep2' : 'sep4');

        $this->authorizeScopeAndTool($customer, $apiKey, $scope, $actionCode);

        $data = array_merge([
            'stems' => 4,
            'model' => 'htdemucs_ft',
            'stemCodec' => 'mp3',
            'stemBitrate' => '192k',
            'storage' => ['mode' => 'temporary'],
        ], $request->all());
        $data['audioFile'] = $request->file('audioFile');

        $validated = Validator::make($data, [
            'audioFile' => self::AUDIO_FILE_RULE,
            'stems' => ['required', 'integer', 'in:2,4'],
            'model' => ['required', 'string', 'max:100'],
            'stemCodec' => ['required', 'string', 'in:mp3'],
            'stemBitrate' => ['required', 'string', 'in:192k'],
            'storage.mode' => ['nullable', 'string', 'in:temporary,permanent'],
        ])->validate();

        $actionCode = (int) $validated['stems'] === 2 ? 'stem.sep2' : 'stem.sep4';
        $storageMode = (string) data_get($validated, 'storage.mode', 'temporary');
        $expiresAt = $this->storageExpiresAt($storageMode);
        $audioFile = $validated['audioFile'];
        $audioInfo = $this->probeAudioFile($audioFile);

        if ((float) $audioInfo['duration_sec'] <= 0) {
            throw new \RuntimeException('Audio duration could not be detected.');
        }

        $audioHash = $this->uploadedFileHash($audioFile);
        $requestHash = $this->requestHash([
            'tool' => 'stem',
            'action' => $actionCode,
            'payload' => [
                'stems' => (int) $validated['stems'],
                'model' => (string) $validated['model'],
                'stemCodec' => (string) $validated['stemCodec'],
                'stemBitrate' => (string) $validated['stemBitrate'],
                'audio_hash' => $audioHash,
                'storage_mode' => $storageMode,
            ],
        ]);

        $usageLog = $this->claimIdempotencyKey($request, $customer, $apiKey, trim((string) $request->header('Idempotency-Key', '')), $requestHash);

        if ($usageLog->api_job_id) {
            return $this->existingJobResponse((string) $usageLog->api_job_id);
        }

        $estimatedCredits = max(0, (int) $customer->priceCreditsFor($actionCode, [
            'outputs' => (int) $validated['stems'],
            'stem_outputs' => (int) $validated['stems'],
            'separation_mode' => (int) $validated['stems'],
            'channel' => PricingRule::CHANNEL_API,
        ]));

        if ($estimatedCredits <= 0) {
            throw new \RuntimeException('Pricing is not configured for this API action.');
        }

        [$tool, $action] = $this->resolveToolAndAction('stem', $actionCode);
        $apiJobId = 'job_'.Str::lower((string) Str::ulid());
        $mlJobId = (string) Str::uuid();
        $reservation = $this->reservations->reserve((int) $customer->id, $apiJobId, $estimatedCredits, [
            'source_type' => 'public_api',
            'tool_code' => 'stem',
            'tool_action' => $actionCode,
            'metric_code' => 'stem_output',
            'metric_quantity' => (int) $validated['stems'],
            'api_key_id' => (int) $apiKey->id,
        ]);

        $savedAudio = null;

        try {
            $audioExt = strtolower((string) ($audioInfo['audio_ext'] ?? $audioFile->getClientOriginalExtension() ?: 'wav'));
            $audioKey = $this->storage->inputPath($customer, 'stem', $mlJobId, 'input', $audioExt);

            DB::transaction(function () use ($mlJobId, $apiJobId, $customer, $apiKey, $tool, $action, $actionCode, $estimatedCredits, $requestHash, $usageLog, $validated, $audioInfo, $audioFile, $audioHash, $storageMode, $expiresAt): void {
                MlJob::create([
                    'id' => $mlJobId,
                    'customer_id' => (int) $customer->id,
                    'tool_id' => (int) $tool->id,
                    'tool_action_id' => (int) $action->id,
                    'job_kind' => 'stem',
                    'status' => 'queued',
                    'provider' => 'runpod',
                    'provider_job_id' => null,
                    'input_hash' => $audioHash,
                    'credits_charged' => $estimatedCredits,
                    'input' => [
                        'separation_mode' => (int) $validated['stems'],
                        'stems' => (int) $validated['stems'],
                        'model' => (string) $validated['model'],
                        'stem_codec' => (string) $validated['stemCodec'],
                        'stem_bitrate' => (string) $validated['stemBitrate'],
                        'audio_name' => (string) $audioFile->getClientOriginalName(),
                        'audio_mime' => (string) ($audioFile->getMimeType() ?: 'audio/*'),
                        'audio_bytes' => (int) ($audioFile->getSize() ?? 0),
                        'audio_duration_sec' => (float) $audioInfo['duration_sec'],
                        'audio_duration_min' => (float) $audioInfo['duration_min'],
                        'audio_billable_min' => (int) $audioInfo['billable_min'],
                        'api_job_id' => $apiJobId,
                        'api_storage_mode' => $storageMode,
                        'api_expires_at' => $expiresAt?->toIso8601String(),
                    ],
                    'started_at' => now(),
                    'storage_in_bytes' => 0,
                    'storage_out_bytes' => 0,
                    'meta' => [
                        'billing_metric' => 'stem_output',
                        'billing_action' => (int) $validated['stems'] === 2 ? 'sep2' : 'sep4',
                        'separation_mode' => (int) $validated['stems'],
                        'duration_seconds' => (float) $audioInfo['duration_sec'],
                        'model' => (string) $validated['model'],
                        'stem_codec' => (string) $validated['stemCodec'],
                        'stem_bitrate' => (string) $validated['stemBitrate'],
                    ],
                ]);

                ApiJob::create([
                    'id' => $apiJobId,
                    'customer_id' => (int) $customer->id,
                    'api_key_id' => (int) $apiKey->id,
                    'ml_job_id' => $mlJobId,
                    'tool_code' => 'stem',
                    'tool_action' => $actionCode,
                    'engine' => 'stem',
                    'status' => 'queued',
                    'input_hash' => $requestHash,
                    'estimated_credits' => $estimatedCredits,
                    'reserved_credits' => $estimatedCredits,
                    'final_credits' => 0,
                    'storage_mode' => $storageMode,
                    'started_at' => now(),
                    'meta' => [
                        'request_payload' => [
                            'stems' => (int) $validated['stems'],
                            'model' => (string) $validated['model'],
                            'stemCodec' => (string) $validated['stemCodec'],
                            'stemBitrate' => (string) $validated['stemBitrate'],
                        ],
                    ],
                ]);

                $usageLog->forceFill([
                    'api_job_id' => $apiJobId,
                    'metric_code' => 'stem_output',
                    'metric_quantity' => (int) $validated['stems'],
                    'status' => 'accepted',
                ])->save();
            }, 3);

            $savedAudio = $this->storage->saveUploadedFileToS3((int) $customer->id, $audioFile, $audioKey, [
                'job_id' => $mlJobId,
                'tool' => 'stem',
                'purpose' => 'input_audio',
                'role' => 'source_audio',
                'checksum' => $audioHash,
                'original_name' => $audioFile->getClientOriginalName(),
            ]);

            $job = MlJob::query()->findOrFail($mlJobId);
            $job->update([
                'input' => array_merge((array) ($job->input ?? []), [
                    'audio_disk' => (string) $savedAudio['disk'],
                    'audio_path' => (string) $savedAudio['path'],
                    'audio_url' => $this->storage->temporaryUrl((string) $savedAudio['path'], 120, [
                        'ResponseContentType' => $savedAudio['mime'] ?? ($audioFile->getMimeType() ?: 'audio/*'),
                    ]),
                    'audio_ext' => $audioExt,
                ]),
                'storage_in_bytes' => (int) $savedAudio['bytes'],
            ]);

            $lock = $this->locks->acquireStemLock(
                customerId: (int) $customer->id,
                jobId: $mlJobId,
                inputHash: $audioHash,
                session: $this->tokenOwnerId($request),
                agent: $request->userAgent(),
                ip: $request->ip(),
            );

            if (! ($lock['ok'] ?? false)) {
                throw new \RuntimeException((string) ($lock['message'] ?? 'Could not lock the STEM job.'));
            }

            $response = $this->runpod->run(
                (string) (config('runpod.endpoints.stem') ?: env('RUNPOD_ENDPOINT_ID_STEM')),
                $this->stemSync->buildRunpodInput(
                    job: $job->fresh(),
                    inputDisk: (string) $savedAudio['disk'],
                    inputPath: (string) $savedAudio['path'],
                    stems: (int) $validated['stems'],
                    model: (string) $validated['model'],
                    stemCodec: (string) $validated['stemCodec'],
                    stemBitrate: (string) $validated['stemBitrate'],
                )
            );

            return $this->markStartedJob($usageLog, $apiJobId, $mlJobId, (string) data_get($response, 'id', ''));
        } catch (\Throwable $e) {
            $this->cleanupSavedInput($customer, $savedAudio, (int) ($audioFile->getSize() ?? 0));

            return $this->handleStartFailure($reservation, $usageLog, $apiJobId, $mlJobId, $e);
        }
    }

    protected function submitVoiceProduct(Request $request, Customer $customer, CustomerApiKey $apiKey, array $config): array
    {
        $scope = (string) $config['scope'];
        $actionCode = (string) $config['action_code'];
        $engine = (string) $config['engine'];

        $this->authorizeScopeAndTool($customer, $apiKey, $scope, $actionCode);

        $availableVoices = $this->voices->voicesForCustomer($customer, $engine)
            ->mapWithKeys(fn ($voice): array => [(string) $voice->code => (string) $voice->name])
            ->all();

        if ($availableVoices === []) {
            throw new \RuntimeException('No voices are available for your current plan.');
        }

        if ($engine === 'ftts') {
            $defaults = array_merge([
                'speaker_id' => (string) array_key_first($availableVoices),
                'storage' => ['mode' => 'temporary'],
            ], $this->f5Defaults());

            $data = $this->normalizedPayload($request, $defaults, ['use_ema', 'remove_silence']);
            $validated = Validator::make($data, [
                'text' => ['required', 'string', 'min:1', 'max:4000'],
                'speaker_id' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($availableVoices): void {
                    if (! array_key_exists((string) $value, $availableVoices)) {
                        $fail(__('The selected speaker is not available for your plan.'));
                    }
                }],
                'use_ema' => ['boolean'],
                'nfe_step' => ['required', 'integer', 'min:1'],
                'cfg_strength' => ['required', 'numeric', 'min:0'],
                'speed' => ['required', 'numeric', 'min:0.1'],
                'remove_silence' => ['boolean'],
                'storage.mode' => ['nullable', 'string', 'in:temporary,permanent'],
            ])->validate();

            $text = trim((string) $validated['text']);
            $chars = mb_strlen($text);
            $storageMode = (string) data_get($validated, 'storage.mode', 'temporary');
            $requestHash = $this->requestHash([
                'tool' => $config['tool_code'],
                'action' => $actionCode,
                'payload' => $validated,
            ]);
            $usageLog = $this->claimIdempotencyKey($request, $customer, $apiKey, trim((string) $request->header('Idempotency-Key', '')), $requestHash);

            if ($usageLog->api_job_id) {
                return $this->existingJobResponse((string) $usageLog->api_job_id);
            }

            $estimatedCredits = max(0, (int) $customer->priceCreditsFor($actionCode, [
                'chars' => $chars,
                'metric_code' => 'character',
                'speaker_key' => (string) $validated['speaker_id'],
                'channel' => PricingRule::CHANNEL_API,
            ]));

            if ($estimatedCredits <= 0) {
                throw new \RuntimeException('Pricing is not configured for this API action.');
            }

            $providerPayload = [
                'mode' => 'f5',
                'speaker_key' => (string) $validated['speaker_id'],
                'gen_text' => $text,
                'return_base64' => true,
                'checkpoint' => '',
                'device' => 'auto',
                'use_ema' => (bool) $validated['use_ema'],
                'nfe_step' => (int) $validated['nfe_step'],
                'cfg_strength' => (float) $validated['cfg_strength'],
                'speed' => (float) $validated['speed'],
                'remove_silence' => (bool) $validated['remove_silence'],
            ];

            return $this->submitSimpleApiJob(
                request: $request,
                customer: $customer,
                apiKey: $apiKey,
                usageLog: $usageLog,
                requestHash: $requestHash,
                toolCode: (string) $config['tool_code'],
                jobKind: (string) $config['job_kind'],
                actionCode: $actionCode,
                engine: 'ftts',
                estimatedCredits: $estimatedCredits,
                storageMode: $storageMode,
                metricCode: 'character',
                metricQuantity: $chars,
                providerPayload: $providerPayload,
                endpointConfig: (string) $config['endpoint_config'],
                requestPayload: $validated,
                additionalInput: [
                    ...$providerPayload,
                    'text' => $text,
                    'speaker_id' => (string) $validated['speaker_id'],
                ],
            );
        }

        $defaults = [
            'speaker_id' => (string) array_key_first($availableVoices),
            'language' => (string) $config['language_default'],
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

        $data = $this->normalizedPayload($request, $defaults, ['split']);
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
        $text = trim((string) $validated['text']);
        $chars = mb_strlen($text);
        $requestHash = $this->requestHash([
            'tool' => $config['tool_code'],
            'action' => $actionCode,
            'payload' => $validated,
        ]);
        $usageLog = $this->claimIdempotencyKey($request, $customer, $apiKey, trim((string) $request->header('Idempotency-Key', '')), $requestHash);

        if ($usageLog->api_job_id) {
            return $this->existingJobResponse((string) $usageLog->api_job_id);
        }

        $estimatedCredits = max(0, (int) $customer->priceCreditsFor($actionCode, [
            'chars' => $chars,
            'metric_code' => 'character',
            'language' => (string) $validated['language'],
            'speaker_id' => (string) $validated['speaker_id'],
            'channel' => PricingRule::CHANNEL_API,
        ]));

        if ($estimatedCredits <= 0) {
            throw new \RuntimeException('Pricing is not configured for this API action.');
        }

        $providerPayload = $engine === 'xomni'
            ? [
                'mode' => 'builtin_ref',
                'text' => $text,
                'ref_audio' => $this->resolveXomniRefAudio($customer, (string) $validated['speaker_id']),
                'ref_text' => '',
                'language' => (string) $validated['language'],
                'output_format' => 'wav',
                'return_base64' => true,
            ]
            : [
                'text' => $text,
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

        return $this->submitSimpleApiJob(
            request: $request,
            customer: $customer,
            apiKey: $apiKey,
            usageLog: $usageLog,
            requestHash: $requestHash,
            toolCode: (string) $config['tool_code'],
            jobKind: (string) $config['job_kind'],
            actionCode: $actionCode,
            engine: $engine,
            estimatedCredits: $estimatedCredits,
            storageMode: $storageMode,
            metricCode: 'character',
            metricQuantity: $chars,
            providerPayload: $providerPayload,
            endpointConfig: (string) $config['endpoint_config'],
            requestPayload: $validated,
            additionalInput: [
                ...$validated,
                'api_job_id' => null,
            ],
        );
    }

    protected function submitCloneProduct(Request $request, Customer $customer, CustomerApiKey $apiKey, array $config): array
    {
        $scope = (string) $config['scope'];
        $actionCode = (string) $config['action_code'];

        $this->authorizeScopeAndTool($customer, $apiKey, $scope, $actionCode);

        $defaults = [
            'language' => (string) $config['language_default'],
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

        $data = $this->normalizedPayload($request, $defaults, ['split']);
        $data['referenceAudio'] = $request->file('referenceAudio');

        $validated = Validator::make($data, [
            'text' => ['required', 'string', 'min:1', 'max:4000'],
            'referenceAudio' => self::CLONE_AUDIO_FILE_RULE,
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

        $referenceAudio = $validated['referenceAudio'];
        $referenceHash = $this->uploadedFileHash($referenceAudio);
        $storageMode = (string) data_get($validated, 'storage.mode', 'temporary');
        $text = trim((string) $validated['text']);
        $chars = mb_strlen($text);

        $requestHash = $this->requestHash([
            'tool' => $config['tool_code'],
            'action' => $actionCode,
            'payload' => [
                'text' => $text,
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
                'reference_hash' => $referenceHash,
                'storage_mode' => $storageMode,
            ],
        ]);

        $usageLog = $this->claimIdempotencyKey($request, $customer, $apiKey, trim((string) $request->header('Idempotency-Key', '')), $requestHash);

        if ($usageLog->api_job_id) {
            return $this->existingJobResponse((string) $usageLog->api_job_id);
        }

        $estimatedCredits = max(0, (int) $customer->priceCreditsFor($actionCode, [
            'chars' => $chars,
            'metric_code' => 'character',
            'language' => (string) $validated['language'],
            'channel' => PricingRule::CHANNEL_API,
        ]));

        if ($estimatedCredits <= 0) {
            throw new \RuntimeException('Pricing is not configured for this API action.');
        }

        [$tool, $action] = $this->resolveToolAndAction((string) $config['tool_code'], $actionCode);
        $apiJobId = 'job_'.Str::lower((string) Str::ulid());
        $mlJobId = (string) Str::uuid();
        $expiresAt = $this->storageExpiresAt($storageMode);
        $reservation = $this->reservations->reserve((int) $customer->id, $apiJobId, $estimatedCredits, [
            'source_type' => 'public_api',
            'tool_code' => (string) $config['tool_code'],
            'tool_action' => $actionCode,
            'metric_code' => 'character',
            'metric_quantity' => $chars,
            'api_key_id' => (int) $apiKey->id,
        ]);

        $savedRef = null;
        $referenceName = (string) ($referenceAudio->getClientOriginalName() ?: 'reference_audio');
        $referenceMime = (string) ($referenceAudio->getMimeType() ?: 'audio/wav');
        $referenceSize = (int) ($referenceAudio->getSize() ?? 0);

        try {
            DB::transaction(function () use ($mlJobId, $apiJobId, $customer, $apiKey, $tool, $action, $actionCode, $estimatedCredits, $requestHash, $usageLog, $validated, $text, $referenceName, $referenceMime, $referenceSize, $storageMode, $expiresAt, $config): void {
                $baseInput = (string) $config['tool_code'] === 'clone_xomni'
                    ? [
                        'mode' => 'audio_url',
                        'text' => $text,
                        'language' => (string) $validated['language'],
                        'text_language' => (string) $validated['language'],
                        'ref_text' => '',
                        'output_format' => 'wav',
                        'return_base64' => true,
                        'ref_max_sec' => 20,
                    ]
                    : [
                        'text' => $text,
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
                    ];

                MlJob::create([
                    'id' => $mlJobId,
                    'customer_id' => (int) $customer->id,
                    'tool_id' => (int) $tool->id,
                    'tool_action_id' => (int) $action->id,
                    'job_kind' => (string) $config['job_kind'],
                    'status' => 'queued',
                    'provider' => 'runpod',
                    'input' => array_merge($baseInput, [
                        'reference_audio_name' => $referenceName,
                        'reference_audio_mime' => $referenceMime,
                        'reference_audio_bytes' => $referenceSize,
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
                    'tool_code' => (string) $config['tool_code'],
                    'tool_action' => $actionCode,
                    'engine' => (string) $config['engine'],
                    'status' => 'queued',
                    'input_hash' => $requestHash,
                    'estimated_credits' => $estimatedCredits,
                    'reserved_credits' => $estimatedCredits,
                    'final_credits' => 0,
                    'storage_mode' => $storageMode,
                    'started_at' => now(),
                    'meta' => [
                        'request_payload' => [
                            'text' => $text,
                            'language' => (string) $validated['language'],
                        ],
                    ],
                ]);

                $usageLog->forceFill([
                    'api_job_id' => $apiJobId,
                    'metric_code' => 'character',
                    'metric_quantity' => mb_strlen($text),
                    'status' => 'accepted',
                ])->save();
            }, 3);

            $refExt = strtolower((string) ($referenceAudio->getClientOriginalExtension() ?: 'wav'));
            $refKey = $this->storage->inputPath($customer, (string) $config['tool_code'], $mlJobId, 'reference', $refExt);

            $savedRef = $this->storage->saveUploadedFileToS3((int) $customer->id, $referenceAudio, $refKey, [
                'job_id' => $mlJobId,
                'tool' => (string) $config['tool_code'],
                'purpose' => 'reference',
                'role' => 'speaker_reference',
                'original_name' => $referenceName,
            ]);

            $audioUrl = $this->storage->temporaryUrl((string) $savedRef['path'], 120, [
                'ResponseContentType' => $savedRef['mime'] ?? $referenceMime,
            ]);

            MlJob::query()->where('id', $mlJobId)->update([
                'input' => array_merge((array) (MlJob::find($mlJobId)?->input ?? []), [
                    'reference_audio_disk' => (string) $savedRef['disk'],
                    'reference_audio_path' => (string) $savedRef['path'],
                    'audio_url' => $audioUrl,
                ]),
                'storage_in_bytes' => (int) $savedRef['bytes'],
            ]);

            $lock = $this->locks->acquireCloneLock(
                customerId: (int) $customer->id,
                jobId: $mlJobId,
                session: $this->tokenOwnerId($request),
                agent: $request->userAgent(),
                ip: $request->ip(),
                jobKind: (string) $config['job_kind'],
            );

            if (! ($lock['ok'] ?? false)) {
                throw new \RuntimeException((string) ($lock['message'] ?? 'Could not lock the clone job.'));
            }

            $providerPayload = (string) $config['tool_code'] === 'clone_xomni'
                ? [
                    'mode' => 'audio_url',
                    'text' => $text,
                    'audio_url' => $audioUrl,
                    'ref_text' => '',
                    'language' => (string) $validated['language'],
                    'text_language' => (string) $validated['language'],
                    'output_format' => 'wav',
                    'return_base64' => true,
                    'ref_max_sec' => 20,
                ]
                : [
                    'text' => $text,
                    'language' => (string) $validated['language'],
                    'speaker_wav' => $audioUrl,
                    'enable_text_splitting' => (bool) $validated['split'],
                    'max_words' => (int) $validated['max_words'],
                    'temperature' => (float) $validated['temperature'],
                    'length_penalty' => (float) $validated['length_penalty'],
                    'repetition_penalty' => (float) $validated['repetition_penalty'],
                    'top_k' => (int) $validated['top_k'],
                    'top_p' => (float) $validated['top_p'],
                    'speed' => (float) $validated['speed'],
                ];

            $response = $this->runpod->run(
                (string) (data_get($tool->meta, 'runpod_endpoint_id') ?: config((string) $config['endpoint_config'])),
                $providerPayload,
                (int) (data_get($tool->meta, 'runpod_timeout') ?: config('runpod.timeout', 60))
            );

            return $this->markStartedJob($usageLog, $apiJobId, $mlJobId, (string) data_get($response, 'id', ''));
        } catch (\Throwable $e) {
            $this->cleanupSavedInput($customer, $savedRef, $referenceSize);

            return $this->handleStartFailure($reservation, $usageLog, $apiJobId, $mlJobId, $e);
        }
    }

    /**
     * @return array{api_job: ApiJob, created: bool}
     */
    protected function submitQasrLike(Request $request, Customer $customer, CustomerApiKey $apiKey, array $config): array
    {
        $scope = (string) $config['scope'];
        $actionCode = (string) $config['action_code'];

        $this->authorizeScopeAndTool($customer, $apiKey, $scope, $actionCode);

        $data = $this->normalizedPayload($request, [
            'modelVariant' => 'fine_tuned',
            'language' => 'ckb',
            'outputFormat' => 'srt',
            'returnSrt' => true,
            'returnSegments' => true,
            'maxWordsPerCaption' => 8,
            'maxCaptionSeconds' => 6,
            'minCaptionSeconds' => 1,
            'storage' => ['mode' => 'temporary'],
        ], ['returnSrt', 'returnSegments']);
        $data['audioFile'] = $request->file('audioFile');

        $rules = [
            'audioFile' => self::AUDIO_FILE_RULE,
            'modelVariant' => ['required', 'string', 'in:fine_tuned'],
            'language' => ['required', 'string', 'in:ckb,ar,en'],
            'storage.mode' => ['nullable', 'string', 'in:temporary,permanent'],
        ];

        if ((string) $config['type'] === 'caption') {
            $rules = array_merge($rules, [
                'outputFormat' => ['required', 'string', 'in:srt'],
                'returnSrt' => ['required', 'boolean'],
                'returnSegments' => ['required', 'boolean'],
                'maxWordsPerCaption' => ['required', 'integer', 'min:1', 'max:20'],
                'maxCaptionSeconds' => ['required', 'integer', 'min:1', 'max:20'],
                'minCaptionSeconds' => ['required', 'integer', 'min:1', 'max:10'],
            ]);
        }

        $validated = Validator::make($data, $rules)->validate();

        $storageMode = (string) data_get($validated, 'storage.mode', 'temporary');
        $expiresAt = $this->storageExpiresAt($storageMode);
        $audioFile = $validated['audioFile'];
        $audioInfo = $this->probeAudioFile($audioFile);

        if ((int) $audioInfo['billable_min'] <= 0) {
            throw new \RuntimeException('Could not calculate billing for this file.');
        }

        $audioHash = $this->uploadedFileHash($audioFile);
        $requestHashPayload = [
            'modelVariant' => (string) $validated['modelVariant'],
            'language' => (string) $validated['language'],
            'audio_hash' => $audioHash,
            'storage_mode' => $storageMode,
        ];

        if ((string) $config['type'] === 'caption') {
            $requestHashPayload = array_merge($requestHashPayload, [
                'outputFormat' => (string) $validated['outputFormat'],
                'returnSrt' => (bool) $validated['returnSrt'],
                'returnSegments' => (bool) $validated['returnSegments'],
                'maxWordsPerCaption' => (int) $validated['maxWordsPerCaption'],
                'maxCaptionSeconds' => (int) $validated['maxCaptionSeconds'],
                'minCaptionSeconds' => (int) $validated['minCaptionSeconds'],
            ]);
        }

        $requestHash = $this->requestHash([
            'tool' => (string) $config['tool_code'],
            'action' => $actionCode,
            'payload' => $requestHashPayload,
        ]);

        $usageLog = $this->claimIdempotencyKey($request, $customer, $apiKey, trim((string) $request->header('Idempotency-Key', '')), $requestHash);

        if ($usageLog->api_job_id) {
            return $this->existingJobResponse((string) $usageLog->api_job_id);
        }

        $estimatedCredits = max(0, (int) $customer->priceCreditsFor($actionCode, [
            'minutes' => (int) $audioInfo['billable_min'],
            'metric_code' => 'minute',
            'model_variant' => (string) $validated['modelVariant'],
            'language' => (string) $validated['language'],
            'output_format' => (string) ($validated['outputFormat'] ?? 'srt'),
            'channel' => PricingRule::CHANNEL_API,
        ]));

        if ($estimatedCredits <= 0) {
            throw new \RuntimeException('Pricing is not configured for this API action.');
        }

        [$tool, $action] = $this->resolveToolAndAction((string) $config['tool_code'], $actionCode);
        $apiJobId = 'job_'.Str::lower((string) Str::ulid());
        $mlJobId = (string) Str::uuid();
        $reservation = $this->reservations->reserve((int) $customer->id, $apiJobId, $estimatedCredits, [
            'source_type' => 'public_api',
            'tool_code' => (string) $config['tool_code'],
            'tool_action' => $actionCode,
            'metric_code' => 'minute',
            'metric_quantity' => (int) $audioInfo['billable_min'],
            'api_key_id' => (int) $apiKey->id,
        ]);

        $savedAudio = null;

        try {
            $audioExt = strtolower((string) ($audioInfo['audio_ext'] ?? $audioFile->getClientOriginalExtension() ?: 'wav'));
            $audioKey = $this->storage->inputPath($customer, (string) $config['tool_code'], $mlJobId, 'audio', $audioExt);

            DB::transaction(function () use ($mlJobId, $apiJobId, $customer, $apiKey, $tool, $action, $actionCode, $estimatedCredits, $requestHash, $usageLog, $validated, $audioInfo, $audioFile, $audioHash, $storageMode, $expiresAt, $config): void {
                $input = [
                    'model_variant' => (string) $validated['modelVariant'],
                    'language' => (string) $validated['language'],
                    'type' => (string) $config['type'],
                    'audio_name' => (string) $audioFile->getClientOriginalName(),
                    'audio_mime' => (string) ($audioFile->getMimeType() ?: 'audio/*'),
                    'audio_bytes' => (int) ($audioFile->getSize() ?? 0),
                    'audio_duration_sec' => (float) $audioInfo['duration_sec'],
                    'audio_duration_min' => (float) $audioInfo['duration_min'],
                    'audio_billable_min' => (int) $audioInfo['billable_min'],
                    'api_job_id' => $apiJobId,
                    'api_storage_mode' => $storageMode,
                    'api_expires_at' => $expiresAt?->toIso8601String(),
                ];

                if ((string) $config['type'] === 'caption') {
                    $input = array_merge($input, [
                        'output_format' => (string) $validated['outputFormat'],
                        'return_srt' => (bool) $validated['returnSrt'],
                        'return_segments' => (bool) $validated['returnSegments'],
                        'max_words_per_caption' => (int) $validated['maxWordsPerCaption'],
                        'max_caption_seconds' => (int) $validated['maxCaptionSeconds'],
                        'min_caption_seconds' => (int) $validated['minCaptionSeconds'],
                    ]);
                }

                MlJob::create([
                    'id' => $mlJobId,
                    'customer_id' => (int) $customer->id,
                    'tool_id' => (int) $tool->id,
                    'tool_action_id' => (int) $action->id,
                    'job_kind' => (string) $config['job_kind'],
                    'status' => 'queued',
                    'provider' => 'runpod',
                    'provider_job_id' => null,
                    'input_hash' => $audioHash,
                    'credits_charged' => $estimatedCredits,
                    'input' => $input,
                    'started_at' => now(),
                    'storage_in_bytes' => 0,
                    'storage_out_bytes' => 0,
                ]);

                ApiJob::create([
                    'id' => $apiJobId,
                    'customer_id' => (int) $customer->id,
                    'api_key_id' => (int) $apiKey->id,
                    'ml_job_id' => $mlJobId,
                    'tool_code' => (string) $config['tool_code'],
                    'tool_action' => $actionCode,
                    'engine' => (string) $config['tool_code'],
                    'status' => 'queued',
                    'input_hash' => $requestHash,
                    'estimated_credits' => $estimatedCredits,
                    'reserved_credits' => $estimatedCredits,
                    'final_credits' => 0,
                    'storage_mode' => $storageMode,
                    'started_at' => now(),
                    'meta' => [
                        'request_payload' => collect($validated)
                            ->except(['audioFile'])
                            ->all(),
                    ],
                ]);

                $usageLog->forceFill([
                    'api_job_id' => $apiJobId,
                    'metric_code' => 'minute',
                    'metric_quantity' => (int) $audioInfo['billable_min'],
                    'status' => 'accepted',
                ])->save();
            }, 3);

            $savedAudio = $this->storage->saveUploadedFileToS3((int) $customer->id, $audioFile, $audioKey, [
                'job_id' => $mlJobId,
                'tool' => (string) $config['tool_code'],
                'purpose' => 'input_audio',
                'role' => 'source_audio',
                'checksum' => $audioHash,
                'original_name' => $audioFile->getClientOriginalName(),
            ]);

            $audioUrl = $this->storage->temporaryUrl((string) $savedAudio['path'], 120, [
                'ResponseContentType' => $savedAudio['mime'] ?? ($audioFile->getMimeType() ?: 'audio/*'),
            ]);

            MlJob::query()->where('id', $mlJobId)->update([
                'input' => array_merge((array) (MlJob::find($mlJobId)?->input ?? []), [
                    'audio_disk' => (string) $savedAudio['disk'],
                    'audio_path' => (string) $savedAudio['path'],
                    'audio_url' => $audioUrl,
                    'audio_ext' => $audioExt,
                ]),
                'storage_in_bytes' => (int) $savedAudio['bytes'],
            ]);

            $lock = $this->locks->acquireAsrLock(
                customerId: (int) $customer->id,
                jobId: $mlJobId,
                inputHash: $audioHash,
                session: $this->tokenOwnerId($request),
                agent: $request->userAgent(),
                ip: $request->ip(),
                jobKind: (string) $config['job_kind'],
            );

            if (! ($lock['ok'] ?? false)) {
                throw new \RuntimeException((string) ($lock['message'] ?? 'Could not acquire ASR lock.'));
            }

            $payload = [
                'audio_url' => $audioUrl,
                'model_variant' => (string) $validated['modelVariant'],
                'language' => (string) $validated['language'],
                'type' => (string) $config['type'],
            ];

            if ((string) $config['type'] === 'caption') {
                $payload = array_merge($payload, [
                    'output_format' => (string) $validated['outputFormat'],
                    'return_srt' => (bool) $validated['returnSrt'],
                    'return_segments' => (bool) $validated['returnSegments'],
                    'max_words_per_caption' => (int) $validated['maxWordsPerCaption'],
                    'max_caption_seconds' => (int) $validated['maxCaptionSeconds'],
                    'min_caption_seconds' => (int) $validated['minCaptionSeconds'],
                ]);
            }

            $response = $this->runpod->run(
                (string) (data_get($tool->meta, 'runpod_endpoint_id') ?: config('runpod.endpoints.qasr') ?: env('RUNPOD_ENDPOINT_ID_QASR')),
                $payload,
                (int) (data_get($tool->meta, 'runpod_timeout') ?: config('runpod.timeout', 60))
            );

            return $this->markStartedJob($usageLog, $apiJobId, $mlJobId, (string) data_get($response, 'id', ''));
        } catch (\Throwable $e) {
            $this->cleanupSavedInput($customer, $savedAudio, (int) ($audioFile->getSize() ?? 0));

            return $this->handleStartFailure($reservation, $usageLog, $apiJobId, $mlJobId, $e);
        }
    }

    protected function authorizeScopeAndTool(Customer $customer, CustomerApiKey $apiKey, string $scope, string $actionCode): void
    {
        if (! $this->scopes->hasScope($apiKey, $scope)) {
            throw new \RuntimeException('This API key is not authorized for the requested scope.');
        }

        if (! $this->access->allowsScope($customer, $scope)) {
            throw new \RuntimeException('Your current plan does not allow this API scope.');
        }

        if (! $customer->isAllowed($actionCode, PlanEntitlement::CHANNEL_API)) {
            throw new \RuntimeException('Your current plan does not allow this tool.');
        }
    }

    /**
     * @return array{api_job: ApiJob, created: bool}
     */
    protected function submitSimpleApiJob(
        Request $request,
        Customer $customer,
        CustomerApiKey $apiKey,
        ApiUsageLog $usageLog,
        string $requestHash,
        string $toolCode,
        string $jobKind,
        string $actionCode,
        string $engine,
        int $estimatedCredits,
        string $storageMode,
        string $metricCode,
        int $metricQuantity,
        array $providerPayload,
        string $endpointConfig,
        array $requestPayload,
        array $additionalInput = [],
    ): array {
        [$tool, $action] = $this->resolveToolAndAction($toolCode, $actionCode);
        $apiJobId = 'job_'.Str::lower((string) Str::ulid());
        $mlJobId = (string) Str::uuid();
        $expiresAt = $this->storageExpiresAt($storageMode);
        $reservation = $this->reservations->reserve((int) $customer->id, $apiJobId, $estimatedCredits, [
            'source_type' => 'public_api',
            'tool_code' => $toolCode,
            'tool_action' => $actionCode,
            'metric_code' => $metricCode,
            'metric_quantity' => $metricQuantity,
            'api_key_id' => (int) $apiKey->id,
        ]);

        try {
            DB::transaction(function () use ($mlJobId, $apiJobId, $customer, $apiKey, $tool, $action, $toolCode, $jobKind, $actionCode, $engine, $estimatedCredits, $requestHash, $usageLog, $storageMode, $expiresAt, $requestPayload, $additionalInput, $metricCode, $metricQuantity): void {
                MlJob::create([
                    'id' => $mlJobId,
                    'customer_id' => (int) $customer->id,
                    'tool_id' => (int) $tool->id,
                    'tool_action_id' => (int) $action->id,
                    'job_kind' => $jobKind,
                    'status' => 'queued',
                    'provider' => 'runpod',
                    'input' => array_merge($additionalInput, [
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
                        'request_payload' => $requestPayload,
                    ],
                ]);

                $usageLog->forceFill([
                    'api_job_id' => $apiJobId,
                    'metric_code' => $metricCode,
                    'metric_quantity' => $metricQuantity,
                    'status' => 'accepted',
                ])->save();
            }, 3);

            $response = $this->runpod->run(
                (string) (data_get($tool->meta, 'runpod_endpoint_id') ?: config($endpointConfig)),
                $providerPayload,
                (int) (data_get($tool->meta, 'runpod_timeout') ?: config('runpod.timeout', 60))
            );

            return $this->markStartedJob($usageLog, $apiJobId, $mlJobId, (string) data_get($response, 'id', ''));
        } catch (\Throwable $e) {
            return $this->handleStartFailure($reservation, $usageLog, $apiJobId, $mlJobId, $e);
        }
    }

    /**
     * @return array{api_job: ApiJob, created: bool}
     */
    protected function markStartedJob(ApiUsageLog $usageLog, string $apiJobId, string $mlJobId, string $providerJobId): array
    {
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

        $usageLog->forceFill(['status' => 'queued'])->save();

        return [
            'api_job' => ApiJob::query()->with(['mlJob.tool', 'resultFiles.storageFile'])->findOrFail($apiJobId),
            'created' => true,
        ];
    }

    /**
     * @return array{api_job: ApiJob, created: bool}
     */
    protected function existingJobResponse(string $apiJobId): array
    {
        $existingJob = ApiJob::query()->findOrFail($apiJobId);

        return [
            'api_job' => $existingJob->fresh(['mlJob.tool', 'resultFiles.storageFile']) ?: $existingJob,
            'created' => false,
        ];
    }

    /**
     * @return array{api_job: ApiJob, created: bool}
     */
    protected function handleStartFailure(mixed $reservation, ApiUsageLog $usageLog, string $apiJobId, string $mlJobId, \Throwable $e): array
    {
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

    protected function claimIdempotencyKey(Request $request, Customer $customer, CustomerApiKey $apiKey, string $idempotencyKey, string $requestHash): ApiUsageLog
    {
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

    protected function resolveXomniRefAudio(Customer $customer, string $speakerId): string
    {
        $refAudio = trim((string) data_get(
            $this->voices->findVoiceForCustomer($customer, 'xomni', $speakerId)?->meta,
            'ref_audio',
            ''
        ));

        if ($refAudio === '') {
            throw new \RuntimeException('The selected Omni voice does not have a reference audio file.');
        }

        return $refAudio;
    }

    protected function resolveToolAndAction(string $toolCode, string $actionCode): array
    {
        $tool = Tool::query()->where('code', $toolCode)->first();
        $action = ToolAction::query()->where('full_code', $actionCode)->first();

        if (! $tool instanceof Tool || ! $action instanceof ToolAction) {
            throw new \RuntimeException('Tool configuration is missing.');
        }

        return [$tool, $action];
    }

    protected function resolveToolAndActionWithFallback(array $toolCodes, string $actionCode): array
    {
        $tool = null;

        foreach ($toolCodes as $toolCode) {
            $tool = Tool::query()->where('code', $toolCode)->first();

            if ($tool instanceof Tool) {
                break;
            }
        }

        $action = ToolAction::query()->where('full_code', $actionCode)->first();

        if (! $tool instanceof Tool || ! $action instanceof ToolAction) {
            throw new \RuntimeException('Tool configuration is missing.');
        }

        return [$tool, $action];
    }

    protected function storageExpiresAt(string $storageMode): ?\DateTimeInterface
    {
        return $storageMode === 'temporary'
            ? now()->addDays((int) config('customer_api.temporary_file_ttl_days', 7))
            : null;
    }

    protected function requestHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    protected function probeAudioFile(UploadedFile $file): array
    {
        try {
            return $this->audioProbe->probeUploadedFile($file);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not determine audio duration.', previous: $e);
        }
    }

    protected function uploadedFileHash(UploadedFile $file): string
    {
        $realPath = $file->getRealPath();

        return $realPath && is_file($realPath)
            ? hash_file('sha256', $realPath)
            : sha1((string) $file->getClientOriginalName().'|'.(int) ($file->getSize() ?? 0));
    }

    protected function cleanupSavedInput(Customer $customer, ?array $savedInput, int $bytesFallback = 0): void
    {
        if (! is_array($savedInput) || empty($savedInput['path'])) {
            return;
        }

        try {
            $this->storage->deleteFromDiskAndUncount(
                (int) $customer->id,
                (string) ($savedInput['disk'] ?? 's3'),
                (string) $savedInput['path'],
                (int) ($savedInput['bytes'] ?? $bytesFallback),
            );
        } catch (\Throwable) {
        }
    }

    protected function tokenOwnerId(Request $request): string
    {
        $tokenId = (string) ($request->attributes->get('customerApiKey')?->id ?? '');

        return $tokenId !== ''
            ? 'api-key:'.$tokenId
            : 'api-key-fallback:'.hash('sha256', implode('|', [
                (string) ($request->user()?->getAuthIdentifier() ?? ''),
                (string) $request->userAgent(),
                (string) $request->ip(),
                config('app.key'),
            ]));
    }

    protected function normalizedPayload(Request $request, array $defaults = [], array $booleanFields = []): array
    {
        $data = array_merge($defaults, $request->all());

        foreach ($booleanFields as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $this->normalizeBoolean($data[$field]);
            }
        }

        return $data;
    }

    protected function normalizeBoolean(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off', '' => false,
                default => $value,
            };
        }

        return $value;
    }

    protected function estimatedOcrPages(int $clientPdfPageCount, string $pageRange): int
    {
        $totalPages = max(1, $clientPdfPageCount ?: 1);
        $range = $this->parsePageRange($pageRange, $totalPages);

        return max(1, count($range ?: range(1, $totalPages)));
    }

    protected function parsePageRange(?string $value, int $maxPages = 0): array
    {
        $value = trim((string) $value);

        if ($value === '') {
            return [];
        }

        $pages = [];
        $parts = preg_split('/\s*,\s*/', $value) ?: [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/', $part, $matches)) {
                $start = (int) $matches[1];
                $end = (int) $matches[2];

                if ($start > 0 && $end > 0 && $start <= $end) {
                    for ($i = $start; $i <= $end; $i++) {
                        $pages[] = $i;
                    }
                }

                continue;
            }

            if (preg_match('/^\d+$/', $part)) {
                $pages[] = (int) $part;
            }
        }

        $pages = array_values(array_unique(array_filter($pages, static fn (int $page): bool => $page > 0)));

        if ($maxPages > 0) {
            $pages = array_values(array_filter($pages, static fn (int $page): bool => $page <= $maxPages));
        }

        sort($pages);

        return $pages;
    }

    protected function f5Defaults(): array
    {
        $toolMeta = (array) (Tool::query()->where('code', 'ftts')->value('meta') ?? []);

        return [
            'use_ema' => (bool) data_get($toolMeta, 'f5tts.use_ema', data_get($toolMeta, 'use_ema', true)),
            'nfe_step' => max(1, (int) data_get($toolMeta, 'f5tts.nfe_step', data_get($toolMeta, 'nfe_step', 32))),
            'cfg_strength' => max(0, (float) data_get($toolMeta, 'f5tts.cfg_strength', data_get($toolMeta, 'cfg_strength', 2.0))),
            'speed' => max(0.1, (float) data_get($toolMeta, 'f5tts.speed', data_get($toolMeta, 'speed', 1.0))),
            'remove_silence' => (bool) data_get($toolMeta, 'f5tts.remove_silence', data_get($toolMeta, 'remove_silence', false)),
        ];
    }

    protected function translationLanguageCatalog(): array
    {
        return [
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
    }
}
