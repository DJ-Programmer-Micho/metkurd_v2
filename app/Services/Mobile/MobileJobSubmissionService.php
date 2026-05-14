<?php

namespace App\Services\Mobile;

use App\Models\Customer;
use App\Models\MlJob;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Services\Billing\CreditService;
use App\Services\Media\AudioProbeService;
use App\Services\OCR\OcrJobSyncService;
use App\Services\Plans\PlanConcurrencyService;
use App\Services\Providers\RunPodProvider;
use App\Services\Security\JobExecutionLockService;
use App\Services\STEM\StemJobSyncService;
use App\Services\Storage\CustomerOutputStorage;
use App\Services\Storage\StorageQuotaExceededException;
use App\Support\AppToolCatalog;
use App\Support\CustomerFolder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MobileJobSubmissionService
{
    protected const AUDIO_FILE_RULE = 'required|file|mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,audio/aac,audio/ogg,audio/webm,audio/flac,audio/x-flac|max:102400';

    protected const CLONE_AUDIO_FILE_RULE = 'required|file|mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,audio/aac,audio/ogg,audio/webm|max:20480';

    public function __construct(
        protected RunPodProvider $runpod,
        protected CreditService $credits,
        protected CustomerOutputStorage $storage,
        protected AudioProbeService $audioProbe,
        protected JobExecutionLockService $locks,
        protected AppToolCatalog $toolCatalog,
        protected MobileTtsVoiceCatalog $voiceCatalog,
        protected PlanConcurrencyService $planConcurrency,
        protected StemJobSyncService $stemSync,
        protected OcrJobSyncService $ocrSync,
    ) {
    }

    /**
     * @return array{message: string, job: MlJob}
     */
    public function submit(Request $request, Customer $customer, string $app): array
    {
        return match (strtolower(trim($app))) {
            'tts' => $this->submitTtsApp($request, $customer),
            'ctts' => $this->submitCloneTtsApp($request, $customer),
            'asr' => $this->submitAsrApp($request, $customer),
            'stem' => $this->submitStemApp($request, $customer),
            'ocr' => $this->submitOcrApp($request, $customer),
            'tran' => $this->submitTranApp($request, $customer),
            default => throw new MobileJobSubmissionException(__('Mobile app scope not found.'), 404),
        };
    }

    /**
     * @return array{message: string, job: MlJob}
     */
    protected function submitTtsApp(Request $request, Customer $customer): array
    {
        $selector = Validator::make($request->all(), [
            'tool_code' => ['required', 'string', 'in:tts,ftts'],
        ])->validate();

        return match ((string) $selector['tool_code']) {
            'tts' => $this->submitXtts($request, $customer),
            'ftts' => $this->submitF5tts($request, $customer),
        };
    }

    /**
     * @return array{message: string, job: MlJob}
     */
    protected function submitAsrApp(Request $request, Customer $customer): array
    {
        $selector = Validator::make($request->all(), [
            'tool_code' => ['required', 'string', 'in:wasr,qasr'],
        ])->validate();

        return match ((string) $selector['tool_code']) {
            'wasr' => $this->submitWasr($request, $customer),
            'qasr' => $this->submitQasr($request, $customer),
        };
    }

    /**
     * @return array{message: string, job: MlJob}
     */
    protected function submitXtts(Request $request, Customer $customer): array
    {
        $fullActionCode = 'tts.standard';
        $availableSpeakers = $this->availableSpeakers($customer, 'xtts');

        if ($availableSpeakers === []) {
            throw new MobileJobSubmissionException(__('No voices are available for your current plan.'), 403);
        }

        $defaults = [
            'speaker_id' => $this->firstAvailableSpeaker($availableSpeakers),
            'language' => 'ar',
            'split' => true,
            'max_words' => 25,
            'fade_ms' => 80,
            'temperature' => 0.65,
            'top_k' => 50,
            'top_p' => 0.8,
            'repetition_penalty' => 2.0,
            'length_penalty' => 1.0,
            'speed' => 1.0,
        ];

        $data = $this->normalizedPayload($request, $defaults, ['split']);
        $validated = Validator::make($data, [
            'text' => ['required', 'string', 'min:1', 'max:' . $this->maxCharsPerSubmit($customer, $fullActionCode, 400), function (string $attribute, mixed $value, \Closure $fail) {
                if (trim((string) $value) === '') {
                    $fail(__('Please enter some text.'));
                }
            }],
            'speaker_id' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($availableSpeakers) {
                if (! array_key_exists((string) $value, $availableSpeakers)) {
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
        ])->validate();

        $this->assertConcurrencyAvailable(
            $customer,
            $this->countActiveJobs($customer, toolCodes: ['tts']),
            __('You reached your concurrent job limit for the current plan.')
        );

        $this->assertToolAllowed($customer, $fullActionCode, __('Your plan does not allow XTTS.'));

        $text = trim((string) $validated['text']);
        $chars = mb_strlen($text);
        $cost = $this->meteredCost($customer, $fullActionCode, [
            'chars' => $chars,
            'metric_code' => 'character',
            'language' => (string) $validated['language'],
            'speaker_id' => (string) $validated['speaker_id'],
        ]);

        if ($cost <= 0) {
            throw new MobileJobSubmissionException(__('Pricing is not configured.'), 422);
        }

        [$tool, $action] = $this->resolveToolAndAction('tts', $fullActionCode);
        $jobId = (string) Str::uuid();
        $charged = false;

        try {
            $this->chargeCredits((int) $customer->id, $cost, 'tts_charge', [
                'related_type' => 'ml_job',
                'related_id' => null,
                'tool_action' => $fullActionCode,
                'chars' => $chars,
            ]);
            $charged = true;

            MlJob::create([
                'id' => $jobId,
                'customer_id' => (int) $customer->id,
                'tool_id' => (int) $tool->id,
                'tool_action_id' => (int) $action->id,
                'job_kind' => 'tts',
                'status' => 'queued',
                'provider' => 'runpod',
                'input' => [
                    'text' => $text,
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
                ],
                'credits_charged' => $cost,
                'started_at' => now(),
            ]);

            $endpointId = (string) (data_get($tool->meta, 'runpod_endpoint_id') ?: config('runpod.endpoints.xtts'));
            if ($endpointId === '') {
                throw new MobileJobSubmissionException(__('XTTS endpoint ID is missing.'), 500);
            }

            $response = $this->runpod->run($endpointId, [
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
            ], (int) (data_get($tool->meta, 'runpod_timeout') ?: config('runpod.timeout', 60)));

            $providerJobId = (string) data_get($response, 'id', '');
            if ($providerJobId === '') {
                throw new MobileJobSubmissionException(__('RunPod did not return a job ID.'), 502);
            }

            MlJob::query()->where('id', $jobId)->update([
                'status' => 'running',
                'provider_job_id' => $providerJobId,
                'updated_at' => now(),
            ]);

            return [
                'message' => __('XTTS job started.'),
                'job' => $this->loadJob($jobId),
            ];
        } catch (\Throwable $e) {
            $this->handleStartFailure(
                customer: $customer,
                jobId: $jobId,
                charged: $charged,
                refundCredits: $cost,
                refundType: 'tts_refund',
                refundMeta: [
                    'related_type' => 'ml_job',
                    'related_id' => $jobId,
                    'tool_action' => $fullActionCode,
                    'reason' => 'provider_start_failed',
                ],
                error: $e,
            );
        }
    }

    /**
     * @return array{message: string, job: MlJob}
     */
    protected function submitF5tts(Request $request, Customer $customer): array
    {
        $fullActionCode = 'ftts.standard';
        $availableSpeakers = $this->availableSpeakers($customer, 'ftts');

        if ($availableSpeakers === []) {
            throw new MobileJobSubmissionException(__('No voices are available for your current plan.'), 403);
        }

        $defaults = array_merge([
            'speaker_id' => $this->firstAvailableSpeaker($availableSpeakers),
        ], $this->f5Defaults());

        $data = $this->normalizedPayload($request, $defaults, ['use_ema', 'remove_silence']);
        $validated = Validator::make($data, [
            'text' => ['required', 'string', 'min:1', 'max:' . $this->maxCharsPerSubmit($customer, $fullActionCode, 400), function (string $attribute, mixed $value, \Closure $fail) {
                if (trim((string) $value) === '') {
                    $fail(__('Please enter some text.'));
                }
            }],
            'speaker_id' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($availableSpeakers) {
                if (! array_key_exists((string) $value, $availableSpeakers)) {
                    $fail(__('The selected speaker is not available for your plan.'));
                }
            }],
            'use_ema' => ['boolean'],
            'nfe_step' => ['required', 'integer', 'min:1'],
            'cfg_strength' => ['required', 'numeric', 'min:0'],
            'speed' => ['required', 'numeric', 'min:0.1'],
            'remove_silence' => ['boolean'],
        ])->validate();

        $this->assertConcurrencyAvailable(
            $customer,
            $this->countActiveJobs($customer, toolCodes: ['ftts']),
            __('You reached your concurrent job limit for the current plan.')
        );

        $this->assertToolAllowed($customer, $fullActionCode, __('Your plan does not allow F5TTS.'));

        $text = trim((string) $validated['text']);
        $chars = mb_strlen($text);
        $cost = $this->meteredCost($customer, $fullActionCode, [
            'chars' => $chars,
            'metric_code' => 'character',
            'speaker_key' => (string) $validated['speaker_id'],
        ]);

        if ($cost <= 0) {
            throw new MobileJobSubmissionException(__('Pricing is not configured.'), 422);
        }

        [$tool, $action] = $this->resolveToolAndAction('ftts', $fullActionCode);
        $payload = [
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

        $jobId = (string) Str::uuid();
        $charged = false;

        try {
            $this->chargeCredits((int) $customer->id, $cost, 'ftts_charge', [
                'related_type' => 'ml_job',
                'related_id' => null,
                'tool_action' => $fullActionCode,
                'chars' => $chars,
            ]);
            $charged = true;

            MlJob::create([
                'id' => $jobId,
                'customer_id' => (int) $customer->id,
                'tool_id' => (int) $tool->id,
                'tool_action_id' => (int) $action->id,
                'job_kind' => 'ftts',
                'status' => 'queued',
                'provider' => 'runpod',
                'input' => $payload,
                'credits_charged' => $cost,
                'started_at' => now(),
            ]);

            $endpointId = (string) (data_get($tool->meta, 'runpod_endpoint_id') ?: config('runpod.endpoints.ftts') ?: env('RUNPOD_ENDPOINT_ID_FTTS'));
            if ($endpointId === '') {
                throw new MobileJobSubmissionException(__('F5TTS endpoint ID is missing.'), 500);
            }

            $response = $this->runpod->run($endpointId, $payload, (int) (data_get($tool->meta, 'runpod_timeout') ?: config('runpod.timeout', 60)));

            $providerJobId = (string) data_get($response, 'id', '');
            if ($providerJobId === '') {
                throw new MobileJobSubmissionException(__('RunPod did not return a job ID.'), 502);
            }

            MlJob::query()->where('id', $jobId)->update([
                'status' => 'running',
                'provider_job_id' => $providerJobId,
                'updated_at' => now(),
            ]);

            return [
                'message' => __('F5TTS job started.'),
                'job' => $this->loadJob($jobId),
            ];
        } catch (\Throwable $e) {
            $this->handleStartFailure(
                customer: $customer,
                jobId: $jobId,
                charged: $charged,
                refundCredits: $cost,
                refundType: 'ftts_refund',
                refundMeta: [
                    'related_type' => 'ml_job',
                    'related_id' => $jobId,
                    'tool_action' => $fullActionCode,
                    'reason' => 'provider_start_failed',
                ],
                error: $e,
            );
        }
    }

    /**
     * @return array{message: string, job: MlJob}
     */
    protected function submitCloneTtsApp(Request $request, Customer $customer): array
    {
        $fullActionCode = 'clone_tts.standard';
        $defaults = [
            'language' => 'ar',
            'split' => true,
            'max_words' => 25,
            'fade_ms' => 80,
            'temperature' => 0.65,
            'top_k' => 50,
            'top_p' => 0.8,
            'repetition_penalty' => 2.0,
            'length_penalty' => 1.0,
            'speed' => 1.0,
        ];

        $data = $this->normalizedPayload($request, $defaults, ['split']);
        $data['referenceAudio'] = $request->file('referenceAudio');

        $validated = Validator::make($data, [
            'text' => ['required', 'string', 'min:1', 'max:' . $this->maxCharsPerSubmit($customer, $fullActionCode, 400), function (string $attribute, mixed $value, \Closure $fail) {
                if (trim((string) $value) === '') {
                    $fail(__('Please enter some text.'));
                }
            }],
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
        ])->validate();

        $this->assertConcurrencyAvailable(
            $customer,
            $this->countActiveJobs($customer, toolCodes: ['clone_tts'], liveLocked: true),
            __('You reached your concurrent job limit for the current plan.')
        );

        $this->assertToolAllowed($customer, $fullActionCode, __('Your plan does not allow Clone XTTS.'));

        $text = trim((string) $validated['text']);
        $chars = mb_strlen($text);
        $cost = $this->meteredCost($customer, $fullActionCode, [
            'chars' => $chars,
            'metric_code' => 'character',
            'language' => (string) $validated['language'],
        ], fallback: (int) ceil($chars * 1.2));

        if ($cost <= 0) {
            throw new MobileJobSubmissionException(__('Pricing is not configured.'), 422);
        }

        [$tool, $action] = $this->resolveToolAndAction('clone_tts', $fullActionCode);
        $jobId = (string) Str::uuid();
        $charged = false;
        $savedRef = null;
        $referenceAudio = $validated['referenceAudio'];
        $referenceName = (string) ($referenceAudio->getClientOriginalName() ?: 'reference_audio');
        $referenceMime = (string) ($referenceAudio->getMimeType() ?: 'audio/wav');
        $referenceSize = (int) ($referenceAudio->getSize() ?? 0);

        try {
            $this->chargeCredits((int) $customer->id, $cost, 'clone_tts_charge', [
                'related_type' => 'ml_job',
                'related_id' => null,
                'tool_action' => $fullActionCode,
                'chars' => $chars,
            ]);
            $charged = true;

            MlJob::create([
                'id' => $jobId,
                'customer_id' => (int) $customer->id,
                'tool_id' => (int) $tool->id,
                'tool_action_id' => (int) $action->id,
                'status' => 'queued',
                'provider' => 'runpod',
                'input' => [
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
                    'reference_audio_name' => $referenceName,
                    'reference_audio_mime' => $referenceMime,
                    'reference_audio_bytes' => $referenceSize,
                ],
                'credits_charged' => $cost,
                'started_at' => now(),
            ]);

            $lock = $this->locks->acquireCloneLock(
                customerId: (int) $customer->id,
                jobId: $jobId,
                session: $this->tokenOwnerId($request),
                agent: $request->userAgent(),
                ip: $request->ip(),
            );

            if (! ($lock['ok'] ?? false)) {
                MlJob::query()->where('id', $jobId)->delete();
                $this->refundCredits((int) $customer->id, $cost, 'clone_tts_refund', [
                    'related_type' => 'ml_job',
                    'related_id' => $jobId,
                    'tool_action' => $fullActionCode,
                    'reason' => 'clone_lock_conflict',
                ]);

                throw new MobileJobSubmissionException(
                    (string) ($lock['message'] ?? __('Clone XTTS is busy on another device.')),
                    409
                );
            }

            $folder = $this->currentFolderForCustomer($customer);
            $refExt = strtolower((string) ($referenceAudio->getClientOriginalExtension() ?: 'wav')) ?: 'wav';
            $refKey = "renders/{$folder}/clone-tts/{$jobId}/ref.{$refExt}";

            $savedRef = $this->storage->saveUploadedFileToS3(
                (int) $customer->id,
                $referenceAudio,
                $refKey,
                [
                    'job_id' => $jobId,
                    'tool' => 'clone_tts',
                    'purpose' => 'reference',
                    'role' => 'speaker_reference',
                    'original_name' => $referenceName,
                ]
            );

            $speakerWavUrl = $this->storage->temporaryUrl((string) $savedRef['path'], 120, [
                'ResponseContentType' => $savedRef['mime'] ?? $referenceMime,
            ]);

            MlJob::query()->where('id', $jobId)->update([
                'input' => array_merge((array) (MlJob::find($jobId)?->input ?? []), [
                    'reference_audio_name' => $referenceName,
                    'reference_audio_mime' => $referenceMime,
                    'reference_audio_bytes' => $referenceSize,
                    'reference_audio_disk' => (string) $savedRef['disk'],
                    'reference_audio_path' => (string) $savedRef['path'],
                    'speaker_wav_url' => $speakerWavUrl,
                    'ref_max_sec' => 15,
                ]),
                'storage_in_bytes' => (int) $savedRef['bytes'],
            ]);

            $endpointId = (string) (data_get($tool->meta, 'runpod_endpoint_id') ?: config('runpod.endpoints.xtts'));
            if ($endpointId === '') {
                throw new MobileJobSubmissionException(__('Clone XTTS endpoint ID is missing.'), 500);
            }

            $response = $this->runpod->run($endpointId, [
                'text' => $text,
                'language' => (string) $validated['language'],
                'speaker_wav_url' => $speakerWavUrl,
                'ref_max_sec' => 15,
                'split' => (bool) $validated['split'],
                'max_words' => (int) $validated['max_words'],
                'fade_ms' => (int) $validated['fade_ms'],
                'temperature' => (float) $validated['temperature'],
                'length_penalty' => (float) $validated['length_penalty'],
                'repetition_penalty' => (float) $validated['repetition_penalty'],
                'top_k' => (int) $validated['top_k'],
                'top_p' => (float) $validated['top_p'],
                'speed' => (float) $validated['speed'],
                'out_path' => '/out/out_3.wav',
            ], (int) (data_get($tool->meta, 'runpod_timeout') ?: config('runpod.timeout', 60)));

            $providerJobId = (string) data_get($response, 'id', '');
            if ($providerJobId === '') {
                throw new MobileJobSubmissionException(__('RunPod did not return a job ID.'), 502);
            }

            MlJob::query()->where('id', $jobId)->update([
                'status' => 'running',
                'provider_job_id' => $providerJobId,
                'updated_at' => now(),
            ]);

            return [
                'message' => __('Clone XTTS job started.'),
                'job' => $this->loadJob($jobId),
            ];
        } catch (\Throwable $e) {
            if ($savedRef && ! empty($savedRef['path'])) {
                try {
                    $this->storage->deleteFromS3AndUncount(
                        (int) $customer->id,
                        (string) $savedRef['path'],
                        (int) ($savedRef['bytes'] ?? $referenceSize)
                    );
                } catch (\Throwable $cleanup) {
                    Log::warning('MOBILE_CLONE_TTS_REF_CLEANUP_FAIL', [
                        'job_id' => $jobId,
                        'path' => $savedRef['path'],
                        'message' => $cleanup->getMessage(),
                    ]);
                }
            }

            $this->handleStartFailure(
                customer: $customer,
                jobId: $jobId,
                charged: $charged,
                refundCredits: $cost,
                refundType: 'clone_tts_refund',
                refundMeta: [
                    'related_type' => 'ml_job',
                    'related_id' => $jobId,
                    'tool_action' => $fullActionCode,
                    'reason' => 'provider_start_failed',
                ],
                error: $e,
            );
        }
    }

    /**
     * @return array{message: string, job: MlJob}
     */
    protected function submitWasr(Request $request, Customer $customer): array
    {
        $data = $this->normalizedPayload($request, [
            'language' => 'ckb',
            'chunkLengthS' => 30,
            'strideLeftS' => 5,
            'strideRightS' => 5,
            'beamSize' => 5,
        ]);
        $data['audioFile'] = $request->file('audioFile');

        $validated = Validator::make($data, [
            'audioFile' => self::AUDIO_FILE_RULE,
            'language' => ['required', 'string', 'in:ckb,ar,en'],
            'chunkLengthS' => ['required', 'integer', 'min:5', 'max:120'],
            'strideLeftS' => ['required', 'integer', 'min:0', 'max:30'],
            'strideRightS' => ['required', 'integer', 'min:0', 'max:30'],
            'beamSize' => ['required', 'integer', 'min:1', 'max:20'],
        ])->validate();

        $this->assertConcurrencyAvailable(
            $customer,
            $this->countActiveJobs($customer, jobKinds: ['asr', 'wasr', 'qasr', 'caption'], liveLocked: true),
            __('You reached your concurrent job limit for the current plan.')
        );

        $fullActionCode = 'asr.standard';
        [$tool, $action] = $this->resolveToolAndActionWithFallback(['wasr', 'asr'], $fullActionCode);
        $this->assertToolAllowed($customer, $fullActionCode, __('Your plan does not allow WASR.'));

        $audioInfo = $this->probeAudioFile($validated['audioFile']);
        $cost = $this->meteredCost($customer, $fullActionCode, [
            'minutes' => (int) $audioInfo['billable_min'],
            'metric_code' => 'minute',
            'language' => (string) $validated['language'],
        ]);

        if ($cost <= 0 || (int) $audioInfo['billable_min'] <= 0) {
            throw new MobileJobSubmissionException(__('Could not calculate billing for this file.'), 422);
        }

        $jobId = (string) Str::uuid();
        $savedAudio = null;
        $charged = false;
        $audioFile = $validated['audioFile'];

        try {
            $this->chargeCredits((int) $customer->id, $cost, 'asr_charge', [
                'related_type' => 'ml_job',
                'related_id' => null,
                'tool_action' => $fullActionCode,
                'minutes' => (int) $audioInfo['billable_min'],
                'seconds' => (float) $audioInfo['duration_sec'],
                'language' => (string) $validated['language'],
            ]);
            $charged = true;

            $audioHash = $this->uploadedFileHash($audioFile);
            $audioExt = strtolower((string) ($audioInfo['audio_ext'] ?? $audioFile->getClientOriginalExtension() ?: 'wav'));
            $folder = $this->currentFolderForCustomer($customer);
            $audioKey = "renders/{$folder}/wasr/{$jobId}/input.{$audioExt}";

            DB::transaction(function () use ($jobId, $customer, $tool, $action, $audioInfo, $audioHash, $cost, $audioFile, $validated): void {
                MlJob::create([
                    'id' => $jobId,
                    'customer_id' => (int) $customer->id,
                    'tool_id' => (int) $tool->id,
                    'tool_action_id' => (int) $action->id,
                    'job_kind' => 'wasr',
                    'status' => 'queued',
                    'provider' => 'runpod',
                    'provider_job_id' => null,
                    'input_hash' => $audioHash,
                    'credits_charged' => $cost,
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
                    ],
                    'started_at' => now(),
                    'storage_in_bytes' => 0,
                    'storage_out_bytes' => 0,
                ]);
            }, 3);

            $savedAudio = $this->storage->saveUploadedFileToS3(
                (int) $customer->id,
                $audioFile,
                $audioKey,
                [
                    'job_id' => $jobId,
                    'tool' => 'wasr',
                    'purpose' => 'input_audio',
                    'role' => 'source_audio',
                    'checksum' => $audioHash,
                    'original_name' => $audioFile->getClientOriginalName(),
                ]
            );

            $audioUrl = $this->storage->temporaryUrl((string) $savedAudio['path'], 120, [
                'ResponseContentType' => $savedAudio['mime'] ?? ($audioFile->getMimeType() ?: 'audio/*'),
            ]);

            MlJob::query()->where('id', $jobId)->update([
                'input' => array_merge((array) (MlJob::find($jobId)?->input ?? []), [
                    'audio_disk' => (string) $savedAudio['disk'],
                    'audio_path' => (string) $savedAudio['path'],
                    'audio_url' => $audioUrl,
                    'audio_ext' => $audioExt,
                ]),
                'storage_in_bytes' => (int) $savedAudio['bytes'],
            ]);

            $lock = $this->locks->acquireAsrLock(
                customerId: (int) $customer->id,
                jobId: $jobId,
                inputHash: $audioHash,
                session: $this->tokenOwnerId($request),
                agent: $request->userAgent(),
                ip: $request->ip(),
            );

            if (! ($lock['ok'] ?? false)) {
                throw new MobileJobSubmissionException(
                    (string) ($lock['message'] ?? __('Could not acquire ASR lock.')),
                    409
                );
            }

            $endpointId = (string) (data_get($tool->meta, 'runpod_endpoint_id') ?: config('runpod.endpoints.wasr') ?: env('RUNPOD_ENDPOINT_ID_WASR'));
            if ($endpointId === '') {
                throw new MobileJobSubmissionException(__('RUNPOD_ENDPOINT_ID_WASR is missing.'), 500);
            }

            $response = $this->runpod->run($endpointId, [
                'audio_url' => $audioUrl,
                'audio_ext' => $audioExt,
                'lang' => (string) $validated['language'],
                'chunk_length_s' => (int) $validated['chunkLengthS'],
                'stride_left_s' => (int) $validated['strideLeftS'],
                'stride_right_s' => (int) $validated['strideRightS'],
                'beam_size' => (int) $validated['beamSize'],
            ], (int) (data_get($tool->meta, 'runpod_timeout') ?: config('runpod.timeout', 60)));

            $providerJobId = (string) data_get($response, 'id', '');
            if ($providerJobId === '') {
                throw new MobileJobSubmissionException(__('RunPod did not return a job ID.'), 502);
            }

            MlJob::query()->where('id', $jobId)->update([
                'status' => 'running',
                'provider_job_id' => $providerJobId,
                'updated_at' => now(),
            ]);

            return [
                'message' => __('WASR job started.'),
                'job' => $this->loadJob($jobId),
            ];
        } catch (\Throwable $e) {
            $this->cleanupSavedInput($customer, $savedAudio, (int) ($audioFile->getSize() ?? 0));

            $this->handleStartFailure(
                customer: $customer,
                jobId: $jobId,
                charged: $charged,
                refundCredits: $cost,
                refundType: 'asr_refund',
                refundMeta: [
                    'related_type' => 'ml_job',
                    'related_id' => $jobId,
                    'tool_action' => $fullActionCode,
                    'reason' => 'provider_start_failed',
                ],
                error: $e,
            );
        }
    }

    /**
     * @return array{message: string, job: MlJob}
     */
    protected function submitQasr(Request $request, Customer $customer): array
    {
        $data = $this->normalizedPayload($request, [
            'modelVariant' => 'fine_tuned',
            'language' => 'ckb',
        ]);
        $data['audioFile'] = $request->file('audioFile');

        $validated = Validator::make($data, [
            'audioFile' => self::AUDIO_FILE_RULE,
            'modelVariant' => ['required', 'string', 'in:fine_tuned'],
            'language' => ['required', 'string', 'in:ckb,ar,en'],
        ])->validate();

        $this->assertConcurrencyAvailable(
            $customer,
            $this->countActiveJobs($customer, jobKinds: ['asr', 'wasr', 'qasr', 'caption'], liveLocked: true),
            __('You reached your concurrent job limit for the current plan.')
        );

        $fullActionCode = 'qasr.standard';
        [$tool, $action] = $this->resolveToolAndAction('qasr', $fullActionCode);
        $this->assertToolAllowed($customer, $fullActionCode, __('Your plan does not allow QASR.'));

        $audioInfo = $this->probeAudioFile($validated['audioFile']);
        $cost = $this->meteredCost($customer, $fullActionCode, [
            'minutes' => (int) $audioInfo['billable_min'],
            'metric_code' => 'minute',
            'model_variant' => (string) $validated['modelVariant'],
        ]);

        if ($cost <= 0 || (int) $audioInfo['billable_min'] <= 0) {
            throw new MobileJobSubmissionException(__('Could not calculate billing for this file.'), 422);
        }

        $jobId = (string) Str::uuid();
        $savedAudio = null;
        $charged = false;
        $audioFile = $validated['audioFile'];

        try {
            $this->chargeCredits((int) $customer->id, $cost, 'asr_charge', [
                'related_type' => 'ml_job',
                'related_id' => null,
                'tool_action' => $fullActionCode,
                'minutes' => (int) $audioInfo['billable_min'],
                'seconds' => (float) $audioInfo['duration_sec'],
                'model_variant' => (string) $validated['modelVariant'],
            ]);
            $charged = true;

            $audioHash = $this->uploadedFileHash($audioFile);
            $audioExt = strtolower((string) ($audioInfo['audio_ext'] ?? $audioFile->getClientOriginalExtension() ?: 'wav'));
            $folder = $this->currentFolderForCustomer($customer);
            $audioKey = "renders/{$folder}/qasr/{$jobId}/audio.wav";

            DB::transaction(function () use ($jobId, $customer, $tool, $action, $audioInfo, $audioHash, $cost, $audioFile, $validated): void {
                MlJob::create([
                    'id' => $jobId,
                    'customer_id' => (int) $customer->id,
                    'tool_id' => (int) $tool->id,
                    'tool_action_id' => (int) $action->id,
                    'job_kind' => 'qasr',
                    'status' => 'queued',
                    'provider' => 'runpod',
                    'provider_job_id' => null,
                    'input_hash' => $audioHash,
                    'credits_charged' => $cost,
                    'input' => [
                        'model_variant' => (string) $validated['modelVariant'],
                        'language' => (string) $validated['language'],
                        'type' => 'asr',
                        'audio_name' => (string) $audioFile->getClientOriginalName(),
                        'audio_mime' => (string) ($audioFile->getMimeType() ?: 'audio/*'),
                        'audio_bytes' => (int) ($audioFile->getSize() ?? 0),
                        'audio_duration_sec' => (float) $audioInfo['duration_sec'],
                        'audio_duration_min' => (float) $audioInfo['duration_min'],
                        'audio_billable_min' => (int) $audioInfo['billable_min'],
                    ],
                    'started_at' => now(),
                    'storage_in_bytes' => 0,
                    'storage_out_bytes' => 0,
                ]);
            }, 3);

            $savedAudio = $this->storage->saveUploadedFileToS3(
                (int) $customer->id,
                $audioFile,
                $audioKey,
                [
                    'job_id' => $jobId,
                    'tool' => 'qasr',
                    'purpose' => 'input_audio',
                    'role' => 'source_audio',
                    'checksum' => $audioHash,
                    'original_name' => $audioFile->getClientOriginalName(),
                ]
            );

            $audioUrl = $this->storage->temporaryUrl((string) $savedAudio['path'], 120, [
                'ResponseContentType' => $savedAudio['mime'] ?? ($audioFile->getMimeType() ?: 'audio/*'),
            ]);

            MlJob::query()->where('id', $jobId)->update([
                'input' => array_merge((array) (MlJob::find($jobId)?->input ?? []), [
                    'audio_disk' => (string) $savedAudio['disk'],
                    'audio_path' => (string) $savedAudio['path'],
                    'audio_url' => $audioUrl,
                    'audio_ext' => $audioExt,
                ]),
                'storage_in_bytes' => (int) $savedAudio['bytes'],
            ]);

            $lock = $this->locks->acquireAsrLock(
                customerId: (int) $customer->id,
                jobId: $jobId,
                inputHash: $audioHash,
                session: $this->tokenOwnerId($request),
                agent: $request->userAgent(),
                ip: $request->ip(),
                jobKind: 'qasr',
            );

            if (! ($lock['ok'] ?? false)) {
                throw new MobileJobSubmissionException(
                    (string) ($lock['message'] ?? __('Could not acquire ASR lock.')),
                    409
                );
            }

            $endpointId = (string) (data_get($tool->meta, 'runpod_endpoint_id') ?: config('runpod.endpoints.qasr') ?: env('RUNPOD_ENDPOINT_ID_QASR'));
            if ($endpointId === '') {
                throw new MobileJobSubmissionException(__('RUNPOD_ENDPOINT_ID_QASR is missing.'), 500);
            }

            $response = $this->runpod->run($endpointId, [
                'audio_url' => $audioUrl,
                'model_variant' => (string) $validated['modelVariant'],
                'language' => (string) $validated['language'],
                'type' => 'asr',
            ], (int) (data_get($tool->meta, 'runpod_timeout') ?: config('runpod.timeout', 60)));

            $providerJobId = (string) data_get($response, 'id', '');
            if ($providerJobId === '') {
                throw new MobileJobSubmissionException(__('RunPod did not return a job ID.'), 502);
            }

            MlJob::query()->where('id', $jobId)->update([
                'status' => 'running',
                'provider_job_id' => $providerJobId,
                'updated_at' => now(),
            ]);

            return [
                'message' => __('QASR job started.'),
                'job' => $this->loadJob($jobId),
            ];
        } catch (\Throwable $e) {
            $this->cleanupSavedInput($customer, $savedAudio, (int) ($audioFile->getSize() ?? 0));

            $this->handleStartFailure(
                customer: $customer,
                jobId: $jobId,
                charged: $charged,
                refundCredits: $cost,
                refundType: 'asr_refund',
                refundMeta: [
                    'related_type' => 'ml_job',
                    'related_id' => $jobId,
                    'tool_action' => $fullActionCode,
                    'reason' => 'provider_start_failed',
                ],
                error: $e,
            );
        }
    }

    /**
     * @return array{message: string, job: MlJob}
     */
    protected function submitStemApp(Request $request, Customer $customer): array
    {
        $data = $this->normalizedPayload($request, [
            'stems' => 4,
            'model' => 'htdemucs_ft',
            'stemCodec' => 'mp3',
            'stemBitrate' => '192k',
        ]);
        $data['audioFile'] = $request->file('audioFile');

        $validated = Validator::make($data, [
            'audioFile' => self::AUDIO_FILE_RULE,
            'stems' => ['required', 'integer', 'in:2,4'],
            'model' => ['required', 'string', 'max:100'],
            'stemCodec' => ['required', 'string', 'in:mp3'],
            'stemBitrate' => ['required', 'string', 'in:192k'],
        ])->validate();

        $actionCode = (int) $validated['stems'] === 2 ? 'sep2' : 'sep4';
        $fullActionCode = 'stem.' . $actionCode;

        $this->assertConcurrencyAvailable(
            $customer,
            $this->countActiveJobs($customer, jobKinds: ['stem'], liveLocked: true),
            __('You reached your concurrent job limit for the current plan.')
        );

        $this->assertToolAllowed($customer, $fullActionCode, __('Your plan does not allow this STEM separation mode.'));

        $audioInfo = $this->probeAudioFile($validated['audioFile']);
        if ((float) $audioInfo['duration_sec'] <= 0) {
            throw new MobileJobSubmissionException(__('Audio duration could not be detected.'), 422);
        }

        $needed = $this->meteredCost($customer, $fullActionCode, [
            'outputs' => (int) $validated['stems'],
            'stem_outputs' => (int) $validated['stems'],
            'separation_mode' => (int) $validated['stems'],
        ], fallback: (int) $validated['stems'] * 500);

        if ($needed <= 0) {
            throw new MobileJobSubmissionException(__('Pricing is not configured for STEM separation.'), 422);
        }

        [$tool, $action] = $this->resolveToolAndAction('stem', $fullActionCode);
        $jobId = (string) Str::uuid();
        $savedAudio = null;
        $charged = false;
        $audioFile = $validated['audioFile'];
        $audioHash = $this->uploadedFileHash($audioFile);

        try {
            $this->chargeCredits((int) $customer->id, $needed, 'stem_charge', [
                'related_type' => 'ml_job',
                'related_id' => $jobId,
                'tool_action' => $fullActionCode,
                'outputs' => (int) $validated['stems'],
                'stem_outputs' => (int) $validated['stems'],
                'separation_mode' => (int) $validated['stems'],
                'seconds' => (float) $audioInfo['duration_sec'],
                'minutes' => (int) $audioInfo['billable_min'],
            ]);
            $charged = true;

            $folder = $this->currentFolderForCustomer($customer);
            $audioExt = strtolower((string) ($audioInfo['audio_ext'] ?? $audioFile->getClientOriginalExtension() ?: 'wav'));
            $audioKey = "renders/{$folder}/stem/{$jobId}/input.{$audioExt}";

            DB::transaction(function () use ($jobId, $customer, $tool, $action, $needed, $validated, $audioInfo, $audioFile, $audioHash, $actionCode): void {
                MlJob::create([
                    'id' => $jobId,
                    'customer_id' => (int) $customer->id,
                    'tool_id' => (int) $tool->id,
                    'tool_action_id' => (int) $action->id,
                    'job_kind' => 'stem',
                    'status' => 'queued',
                    'provider' => 'runpod',
                    'provider_job_id' => null,
                    'input_hash' => $audioHash,
                    'credits_charged' => $needed,
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
                    ],
                    'started_at' => now(),
                    'storage_in_bytes' => 0,
                    'storage_out_bytes' => 0,
                    'meta' => [
                        'billing_metric' => 'stem_output',
                        'billing_action' => $actionCode,
                        'separation_mode' => (int) $validated['stems'],
                        'duration_seconds' => (float) $audioInfo['duration_sec'],
                        'model' => (string) $validated['model'],
                        'stem_codec' => (string) $validated['stemCodec'],
                        'stem_bitrate' => (string) $validated['stemBitrate'],
                    ],
                ]);
            }, 3);

            $savedAudio = $this->storage->saveUploadedFileToS3(
                (int) $customer->id,
                $audioFile,
                $audioKey,
                [
                    'job_id' => $jobId,
                    'tool' => 'stem',
                    'purpose' => 'input_audio',
                    'role' => 'source_audio',
                    'checksum' => $audioHash,
                    'original_name' => $audioFile->getClientOriginalName(),
                ]
            );

            $audioUrl = $this->storage->temporaryUrl((string) $savedAudio['path'], 120, [
                'ResponseContentType' => $savedAudio['mime'] ?? ($audioFile->getMimeType() ?: 'audio/*'),
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

            $lock = $this->locks->acquireStemLock(
                customerId: (int) $customer->id,
                jobId: $jobId,
                inputHash: $audioHash,
                session: $this->tokenOwnerId($request),
                agent: $request->userAgent(),
                ip: $request->ip(),
            );

            if (! ($lock['ok'] ?? false)) {
                throw new MobileJobSubmissionException(
                    (string) ($lock['message'] ?? __('Could not lock the STEM job.')),
                    409
                );
            }

            $payload = $this->stemSync->buildRunpodInput(
                job: $job->fresh(),
                inputDisk: (string) $savedAudio['disk'],
                inputPath: (string) $savedAudio['path'],
                stems: (int) $validated['stems'],
                model: (string) $validated['model'],
                stemCodec: (string) $validated['stemCodec'],
                stemBitrate: (string) $validated['stemBitrate'],
            );

            $endpointId = (string) (config('runpod.endpoints.stem') ?: env('RUNPOD_ENDPOINT_ID_STEM'));
            if ($endpointId === '') {
                throw new MobileJobSubmissionException(__('RUNPOD_ENDPOINT_ID_STEM is missing.'), 500);
            }

            $response = $this->runpod->run($endpointId, $payload);
            $providerJobId = (string) data_get($response, 'id', '');

            if ($providerJobId === '') {
                throw new MobileJobSubmissionException(__('RunPod did not return a provider job ID.'), 502);
            }

            $job->update([
                'provider_job_id' => $providerJobId,
                'status' => 'running',
            ]);

            return [
                'message' => __('STEM job submitted.'),
                'job' => $this->loadJob($jobId),
            ];
        } catch (\Throwable $e) {
            $this->cleanupSavedInput($customer, $savedAudio, (int) ($audioFile->getSize() ?? 0));

            try {
                $this->locks->releaseLock($jobId);
            } catch (\Throwable) {
            }

            $this->handleStartFailure(
                customer: $customer,
                jobId: $jobId,
                charged: $charged,
                refundCredits: $needed,
                refundType: 'stem_refund',
                refundMeta: [
                    'related_type' => 'ml_job',
                    'related_id' => $jobId,
                    'tool_action' => $fullActionCode,
                    'reason' => $e instanceof MobileJobSubmissionException && $e->status() === 409
                        ? 'stem_lock_conflict'
                        : 'provider_start_failed',
                ],
                error: $e,
            );
        }
    }

    /**
     * @return array{message: string, job: MlJob}
     */
    protected function submitOcrApp(Request $request, Customer $customer): array
    {
        $data = $this->normalizedPayload($request, [
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
        ], ['normalize', 'grayscale', 'autocontrast', 'sharpen', 'binarize']);
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
        ])->validate();

        $fullActionCode = 'ocr.standard';
        $this->assertToolAllowed($customer, $fullActionCode, __('Your plan does not allow OCR.'));

        $active = MlJob::query()
            ->where('customer_id', (int) $customer->id)
            ->where('job_kind', 'ocr')
            ->whereIn('status', ['queued', 'running', 'saving'])
            ->exists();

        if ($active) {
            throw new MobileJobSubmissionException(__('You already have an OCR job in progress.'), 409);
        }

        $pages = $this->estimatedOcrPages(
            (int) ($validated['clientPdfPageCount'] ?: 1),
            (string) ($validated['pageRange'] ?? '')
        );

        $needed = $this->meteredCost($customer, $fullActionCode, [
            'pages' => $pages,
            'lang' => (string) $validated['lang'],
            'dpi' => (int) $validated['dpi'],
        ], fallback: max(100, $pages * 100));

        if ($needed <= 0) {
            throw new MobileJobSubmissionException(__('Pricing is not configured for OCR.'), 422);
        }

        [$tool, $action] = $this->resolveToolAndAction('ocr', $fullActionCode);
        $jobId = (string) Str::uuid();
        $savedInput = null;
        $charged = false;
        $documentFile = $validated['documentFile'];
        $documentHash = $this->uploadedFileHash($documentFile);
        $documentExt = strtolower((string) ($documentFile->getClientOriginalExtension() ?: 'pdf'));

        try {
            $this->chargeCredits((int) $customer->id, $needed, 'ocr_charge', [
                'related_type' => 'ml_job',
                'related_id' => $jobId,
                'tool_action' => $fullActionCode,
                'pages' => $pages,
                'lang' => (string) $validated['lang'],
                'dpi' => (int) $validated['dpi'],
            ]);
            $charged = true;

            $baseDir = "renders/{$this->currentFolderForCustomer($customer)}/ocr/{$jobId}";
            $inputPath = "{$baseDir}/input.{$documentExt}";

            DB::transaction(function () use ($jobId, $customer, $tool, $action, $needed, $validated, $documentFile, $documentHash, $documentExt, $pages): void {
                MlJob::create([
                    'id' => $jobId,
                    'customer_id' => (int) $customer->id,
                    'tool_id' => (int) $tool->id,
                    'tool_action_id' => (int) $action->id,
                    'job_kind' => 'ocr',
                    'status' => 'queued',
                    'provider' => 'runpod',
                    'provider_job_id' => null,
                    'input_hash' => $documentHash,
                    'credits_charged' => $needed,
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
                    ],
                    'started_at' => now(),
                    'storage_in_bytes' => 0,
                    'storage_out_bytes' => 0,
                    'meta' => [
                        'billing_metric' => 'ocr_page',
                        'billing_action' => 'standard',
                    ],
                ]);
            }, 3);

            $savedInput = $this->storage->saveUploadedFileToS3(
                (int) $customer->id,
                $documentFile,
                $inputPath,
                [
                    'job_id' => $jobId,
                    'tool' => 'ocr',
                    'purpose' => 'input_document',
                    'role' => 'source_pdf',
                    'checksum' => $documentHash,
                    'original_name' => $documentFile->getClientOriginalName(),
                ]
            );

            $inputUrl = $this->storage->temporaryUrl((string) $savedInput['path'], 120, [
                'ResponseContentType' => $savedInput['mime'] ?? 'application/pdf',
            ]);

            $job = MlJob::query()->findOrFail($jobId);
            $job->update([
                'input' => array_merge((array) ($job->input ?? []), [
                    'file_disk' => (string) $savedInput['disk'],
                    'file_path' => (string) $savedInput['path'],
                    'file_url' => (string) $inputUrl,
                ]),
                'storage_in_bytes' => (int) $savedInput['bytes'],
            ]);

            $lock = $this->locks->acquireOcrLock(
                customerId: (int) $customer->id,
                jobId: $jobId,
                inputHash: $documentHash,
                session: $this->tokenOwnerId($request),
                agent: $request->userAgent(),
                ip: $request->ip(),
            );

            if (! ($lock['ok'] ?? false)) {
                throw new MobileJobSubmissionException(
                    (string) ($lock['message'] ?? __('Could not lock the OCR job.')),
                    409
                );
            }

            $payload = $this->ocrSync->buildRunpodInput(
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
            );

            $endpointId = (string) (config('runpod.endpoints.kocr') ?: env('RUNPOD_ENDPOINT_ID_KOCR'));
            if ($endpointId === '') {
                throw new MobileJobSubmissionException(__('RUNPOD_ENDPOINT_ID_KOCR is missing.'), 500);
            }

            $response = $this->runpod->run($endpointId, $payload);
            $providerJobId = (string) data_get($response, 'id', '');

            if ($providerJobId === '') {
                throw new MobileJobSubmissionException(__('RunPod did not return a provider job ID.'), 502);
            }

            $job->update([
                'provider_job_id' => $providerJobId,
                'status' => 'running',
            ]);

            return [
                'message' => __('OCR job submitted.'),
                'job' => $this->loadJob($jobId),
            ];
        } catch (\Throwable $e) {
            $this->cleanupSavedInput($customer, $savedInput, (int) ($documentFile->getSize() ?? 0));

            try {
                $this->locks->releaseLock($jobId);
            } catch (\Throwable) {
            }

            $this->handleStartFailure(
                customer: $customer,
                jobId: $jobId,
                charged: $charged,
                refundCredits: $needed,
                refundType: 'ocr_refund',
                refundMeta: [
                    'related_type' => 'ml_job',
                    'related_id' => $jobId,
                    'tool_action' => $fullActionCode,
                    'reason' => $e instanceof MobileJobSubmissionException && $e->status() === 409
                        ? 'ocr_lock_conflict'
                        : 'provider_start_failed',
                ],
                error: $e,
            );
        }
    }

    /**
     * @return array{message: string, job: MlJob}
     */
    protected function submitTranApp(Request $request, Customer $customer): array
    {
        $fullActionCode = 'tran.standard';
        $defaults = [
            'sourceLang' => 'ku',
            'targetLang' => 'en',
            'maxNewTokens' => 256,
            'chunkChars' => 1200,
        ];

        $data = $this->normalizedPayload($request, $defaults);
        $validated = Validator::make($data, [
            'text' => ['required', 'string', 'min:1', 'max:' . $this->maxCharsPerSubmit($customer, $fullActionCode, 2400), function (string $attribute, mixed $value, \Closure $fail) {
                if (trim((string) $value) === '') {
                    $fail(__('Please enter text to translate.'));
                }
            }],
            'sourceLang' => ['required', 'string', 'in:' . implode(',', array_keys($this->translationLanguageCatalog()))],
            'targetLang' => ['required', 'string', 'in:' . implode(',', array_keys($this->translationLanguageCatalog())), 'different:sourceLang'],
            'maxNewTokens' => ['required', 'integer', 'min:64', 'max:2048'],
            'chunkChars' => ['required', 'integer', 'min:200', 'max:5000'],
        ])->validate();

        $this->assertConcurrencyAvailable(
            $customer,
            $this->countActiveJobs($customer, toolCodes: ['tran']),
            __('You reached your concurrent job limit for the current plan.')
        );

        $this->assertToolAllowed($customer, $fullActionCode, __('Your plan does not allow MET Translation.'));

        $text = trim((string) $validated['text']);
        $chars = mb_strlen($text);
        $cost = $this->meteredCost($customer, $fullActionCode, [
            'chars' => $chars,
            'metric_code' => 'character',
            'source_lang' => (string) $validated['sourceLang'],
            'target_lang' => (string) $validated['targetLang'],
        ]);

        if ($cost <= 0) {
            throw new MobileJobSubmissionException(__('Pricing is not configured.'), 422);
        }

        [$tool, $action] = $this->resolveToolAndAction('tran', $fullActionCode);
        $jobId = (string) Str::uuid();
        $savedSource = null;
        $charged = false;

        try {
            $this->chargeCredits((int) $customer->id, $cost, 'tran_charge', [
                'related_type' => 'ml_job',
                'related_id' => null,
                'tool_action' => $fullActionCode,
                'chars' => $chars,
                'source_lang' => (string) $validated['sourceLang'],
                'target_lang' => (string) $validated['targetLang'],
            ]);
            $charged = true;

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
                    'source_lang' => (string) $validated['sourceLang'],
                    'target_lang' => (string) $validated['targetLang'],
                    'max_new_tokens' => (int) $validated['maxNewTokens'],
                    'chunk_chars' => (int) $validated['chunkChars'],
                    'source_char_count' => $chars,
                ],
                'credits_charged' => $cost,
                'started_at' => now(),
                'storage_in_bytes' => 0,
                'storage_out_bytes' => 0,
            ]);

            $folder = $this->currentFolderForCustomer($customer);
            $sourceKey = "renders/{$folder}/tran/{$jobId}/source.txt";

            $savedSource = $this->storage->saveTextToS3(
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
                    'source_disk' => (string) $savedSource['disk'],
                    'source_path' => (string) $savedSource['path'],
                    'source_bytes' => (int) $savedSource['bytes'],
                ]),
                'storage_in_bytes' => (int) $savedSource['bytes'],
            ]);

            $endpointId = (string) (data_get($tool->meta, 'runpod_endpoint_id') ?: config('runpod.endpoints.tran') ?: env('RUNPOD_ENDPOINT_ID_TRAN'));
            if ($endpointId === '') {
                throw new MobileJobSubmissionException(__('RUNPOD_ENDPOINT_ID_TRAN is missing.'), 500);
            }

            $response = $this->runpod->run($endpointId, [
                'text' => $text,
                'source_lang' => (string) $validated['sourceLang'],
                'target_lang' => (string) $validated['targetLang'],
                'max_new_tokens' => (int) $validated['maxNewTokens'],
                'chunk_chars' => (int) $validated['chunkChars'],
            ], (int) (data_get($tool->meta, 'runpod_timeout') ?: config('runpod.timeout', 60)));

            $providerJobId = (string) data_get($response, 'id', '');
            if ($providerJobId === '') {
                throw new MobileJobSubmissionException(__('RunPod did not return a job ID.'), 502);
            }

            MlJob::query()->where('id', $jobId)->update([
                'status' => 'running',
                'provider_job_id' => $providerJobId,
                'execution_scope' => 'customer',
                'lock_expires_at' => now()->addMinutes(60),
                'updated_at' => now(),
            ]);

            return [
                'message' => __('Translation job started.'),
                'job' => $this->loadJob($jobId),
            ];
        } catch (\Throwable $e) {
            $this->cleanupSavedInput($customer, $savedSource, strlen($text));

            $this->handleStartFailure(
                customer: $customer,
                jobId: $jobId,
                charged: $charged,
                refundCredits: $cost,
                refundType: 'tran_refund',
                refundMeta: [
                    'related_type' => 'ml_job',
                    'related_id' => $jobId,
                    'tool_action' => $fullActionCode,
                    'reason' => 'provider_start_failed',
                ],
                error: $e,
            );
        }
    }

    protected function loadJob(string $jobId): MlJob
    {
        return MlJob::query()
            ->with([
                'tool:id,code,name',
                'toolAction:id,tool_code,full_code,name',
            ])
            ->findOrFail($jobId);
    }

    protected function assertConcurrencyAvailable(Customer $customer, int $activeCount, string $message): void
    {
        if ($activeCount >= $this->planConcurrency->allowedConcurrentJobsForCustomer($customer)) {
            throw new MobileJobSubmissionException($message, 409);
        }
    }

    protected function assertToolAllowed(Customer $customer, string $fullActionCode, string $message): void
    {
        if (method_exists($customer, 'isAllowed') && ! $customer->isAllowed($fullActionCode)) {
            throw new MobileJobSubmissionException($message, 403);
        }
    }

    protected function chargeCredits(int $customerId, int $credits, string $type, array $meta): void
    {
        try {
            $this->credits->charge($customerId, $credits, $type, $meta);
        } catch (\Throwable $e) {
            throw new MobileJobSubmissionException(__('Not enough credits.'), 422, previous: $e);
        }
    }

    protected function refundCredits(int $customerId, int $credits, string $type, array $meta): void
    {
        $this->credits->refund($customerId, $credits, $type, $meta);
    }

    protected function meteredCost(Customer $customer, string $fullActionCode, array $context, int $fallback = 0): int
    {
        if (method_exists($customer, 'priceCreditsFor')) {
            $calculated = (int) $customer->priceCreditsFor($fullActionCode, $context);

            if ($calculated > 0) {
                return $calculated;
            }
        }

        return max(0, $fallback);
    }

    protected function maxCharsPerSubmit(Customer $customer, string $fullActionCode, int $default): int
    {
        if (method_exists($customer, 'entitlementLimitFor')) {
            return (int) ($customer->entitlementLimitFor($fullActionCode, 'max_chars_per_submit') ?? $default);
        }

        return $default;
    }

    protected function availableSpeakers(Customer $customer, string $engine): array
    {
        return $this->voiceCatalog->speakerOptionsForCustomer($customer, $engine);
    }

    protected function firstAvailableSpeaker(array $speakers): string
    {
        return (string) (array_key_first($speakers) ?? '');
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

    protected function currentFolderForCustomer(Customer $customer): string
    {
        $customer->loadMissing('profile');

        return CustomerFolder::make(
            (int) $customer->id,
            $customer->profile?->first_name ?? $customer->first_name ?? null,
            $customer->profile?->last_name ?? $customer->last_name ?? null,
            $customer->username ?? null,
        );
    }

    protected function countActiveJobs(Customer $customer, array $toolCodes = [], array $jobKinds = [], bool $liveLocked = false): int
    {
        $query = MlJob::query()
            ->where('customer_id', (int) $customer->id)
            ->whereIn('status', ['queued', 'running', 'saving']);

        if ($toolCodes !== []) {
            $toolIds = $this->toolCatalog->toolIds($toolCodes);
            $query->whereIn('tool_id', $toolIds === [] ? [-1] : $toolIds);
        }

        if ($jobKinds !== []) {
            $query->whereIn('job_kind', $jobKinds);
        }

        if ($liveLocked) {
            $query->whereNotNull('lock_expires_at')
                ->where('lock_expires_at', '>', now());
        }

        return (int) $query->count();
    }

    protected function resolveToolAndAction(string $toolCode, string $fullActionCode): array
    {
        $tool = Tool::query()->where('code', $toolCode)->first();
        $action = ToolAction::query()->where('full_code', $fullActionCode)->first();

        if (! $tool || ! $action) {
            throw new MobileJobSubmissionException(
                __('Tool or ToolAction is missing for :tool.', ['tool' => "{$toolCode} / {$fullActionCode}"]),
                500
            );
        }

        return [$tool, $action];
    }

    protected function resolveToolAndActionWithFallback(array $toolCodes, string $fullActionCode): array
    {
        $tool = null;

        foreach ($toolCodes as $toolCode) {
            $tool = Tool::query()->where('code', $toolCode)->first();

            if ($tool) {
                break;
            }
        }

        $action = ToolAction::query()->where('full_code', $fullActionCode)->first();

        if (! $tool || ! $action) {
            throw new MobileJobSubmissionException(
                __('Tool or ToolAction is missing for :tool.', ['tool' => implode(' / ', $toolCodes) . ' / ' . $fullActionCode]),
                500
            );
        }

        return [$tool, $action];
    }

    protected function probeAudioFile(UploadedFile $file): array
    {
        try {
            return $this->audioProbe->probeUploadedFile($file);
        } catch (\Throwable $e) {
            throw new MobileJobSubmissionException(__('Could not determine audio duration.'), 422, previous: $e);
        }
    }

    protected function uploadedFileHash(UploadedFile $file): string
    {
        $realPath = $file->getRealPath();

        return $realPath && is_file($realPath)
            ? hash_file('sha256', $realPath)
            : sha1((string) $file->getClientOriginalName() . '|' . (int) ($file->getSize() ?? 0));
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

    protected function tokenOwnerId(Request $request): string
    {
        $tokenId = (string) ($request->user()?->currentAccessToken()?->id ?? '');

        return $tokenId !== ''
            ? 'token:' . $tokenId
            : 'token-fallback:' . hash('sha256', implode('|', [
                (string) ($request->user()?->getAuthIdentifier() ?? ''),
                (string) $request->userAgent(),
                (string) $request->ip(),
                config('app.key'),
            ]));
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
        } catch (\Throwable $cleanup) {
            Log::warning('MOBILE_JOB_INPUT_CLEANUP_FAIL', [
                'customer_id' => (int) $customer->id,
                'path' => $savedInput['path'] ?? null,
                'message' => $cleanup->getMessage(),
            ]);
        }
    }

    protected function handleStartFailure(
        Customer $customer,
        string $jobId,
        bool $charged,
        int $refundCredits,
        string $refundType,
        array $refundMeta,
        \Throwable $error,
    ): never {
        if ($charged) {
            try {
                $this->refundCredits((int) $customer->id, $refundCredits, $refundType, $refundMeta);
            } catch (\Throwable $refundError) {
                Log::warning('MOBILE_JOB_REFUND_FAIL', [
                    'job_id' => $jobId,
                    'message' => $refundError->getMessage(),
                ]);
            }
        }

        if (MlJob::query()->where('id', $jobId)->exists()) {
            MlJob::query()->where('id', $jobId)->update([
                'status' => 'failed',
                'error' => ['message' => $error->getMessage()],
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($error instanceof MobileJobSubmissionException) {
            throw $error;
        }

        if ($error instanceof StorageQuotaExceededException) {
            throw new MobileJobSubmissionException($error->getMessage(), 409, previous: $error);
        }

        throw new MobileJobSubmissionException($error->getMessage() !== '' ? $error->getMessage() : __('Job failed to start.'), 422, previous: $error);
    }
}
