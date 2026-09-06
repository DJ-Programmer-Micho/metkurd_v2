<?php

namespace App\Services\MetKurd\Jobs;

use App\Models\Customer;
use App\Models\MlJob;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Services\Billing\CreditService;
use App\Services\MetKurd\V2\CaptionWorkspaceCache;
use App\Services\MetKurd\V2\RunPodV2Adapter;
use App\Services\Security\JobExecutionLockService;
use App\Services\Storage\CustomerOutputStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Creates V2 Caption jobs without altering the established Caption worker payload. */
class CaptionSubmissionService
{
    public function __construct(
        private readonly CreditService $credits,
        private readonly CustomerOutputStorage $storage,
        private readonly RunPodV2Adapter $provider,
        private readonly MlJobRefundService $refunds,
        private readonly JobExecutionLockService $locks,
        private readonly CaptionWorkspaceCache $workspaceCache,
    ) {}

    /** @param array{model_variant:string,language:string,intelligent:bool,duration_sec:float,billable_minutes:int,input_hash:string} $options */
    public function submit(Customer $customer, UploadedFile $audio, array $options, ?SubmissionContext $context = null): MlJob
    {
        $context ??= new SubmissionContext;
        $tool = Tool::query()->where('code', 'caption')->firstOrFail();
        $action = ToolAction::query()->where('full_code', 'caption.standard')->firstOrFail();
        $minutes = max(0, (int) ($options['billable_minutes'] ?? 0));
        $language = strtolower(trim((string) ($options['language'] ?? 'ckb')));
        $model = trim((string) ($options['model_variant'] ?? 'fine_tuned'));
        $intelligent = (bool) ($options['intelligent'] ?? false);
        $captionOptions = [
            'output_format' => 'srt', 'return_srt' => true, 'return_segments' => true,
            'max_words_per_caption' => 8, 'max_caption_seconds' => 6, 'min_caption_seconds' => 1,
        ];

        if (! $audio->isValid() || $minutes < 1 || $model !== 'fine_tuned' || ! in_array($language, ['ckb', 'ar', 'en'], true)) {
            throw new \InvalidArgumentException('The audio upload or caption options are invalid.');
        }
        if (method_exists($customer, 'isAllowed') && ! $customer->isAllowed('caption.standard', $context->channel())) {
            throw new \RuntimeException('Your plan does not allow Caption.');
        }

        $cost = max(0, (int) $customer->priceCreditsFor('caption.standard', [
            'channel' => $context->channel(),
            'minutes' => $minutes, 'metric_code' => 'minute', 'model_variant' => $model, 'language' => $language, 'output_format' => 'srt',
        ]));
        if ($cost < 1) {
            throw new \RuntimeException('Pricing is not configured for Caption.');
        }

        $jobId = (string) Str::uuid();
        $charged = false;

        [$job, $created] = app(DurableUploadSubmission::class)->begin((int) $customer->id,
            (string) ($options['submission_key'] ?? Str::uuid()), 'caption.standard', $cost, 'caption_generate_charge', ['minutes' => $minutes, 'seconds' => (float) ($options['duration_sec'] ?? 0), 'intelligent' => $intelligent], function () use ($customer, $tool, $action, $jobId, $cost, $model, $language, $intelligent, $captionOptions, $options) {
                return MlJob::create([
                    'id' => $jobId, 'customer_id' => (int) $customer->id, 'tool_id' => (int) $tool->id, 'tool_action_id' => (int) $action->id,
                    'job_kind' => 'caption', 'status' => 'queued', 'provider' => 'runpod', 'endpoint_key' => 'qasr_v2',
                    'charge_reference' => "ml-job:{$jobId}:charge", 'input_hash' => (string) ($options['input_hash'] ?? ''), 'credits_charged' => $cost,
                    'input' => array_merge($captionOptions, [
                        'type' => 'caption', 'model_variant' => $model, 'language' => $language, 'intelligent' => $intelligent ? 1 : 0,
                        'audio_name' => (string) ($options['audio_name'] ?? 'audio.wav'), 'audio_duration_sec' => (float) ($options['duration_sec'] ?? 0),
                        'audio_billable_min' => (int) ($options['billable_minutes'] ?? 0), 'audio_mime' => (string) ($options['audio_mime'] ?? 'audio/*'),
                    ]),
                ]);
            }, $context);
        if (! $created) {
            return $job;
        }
        try {
            $savedAudio = $this->storage->saveUploadedFileToS3((int) $customer->id, $audio, $this->storage->inputPath($customer, 'caption', $jobId, 'wav', 'audio'), [
                'job_id' => $jobId, 'tool' => 'caption', 'purpose' => 'input_audio', 'role' => 'source_audio',
                'checksum' => (string) ($options['input_hash'] ?? ''), 'original_name' => (string) ($options['audio_name'] ?? $audio->getClientOriginalName()),
            ]);
            $audioUrl = $this->storage->temporaryUrl((string) $savedAudio['path'], 120, ['ResponseContentType' => (string) $savedAudio['mime']]);
            MlJob::query()->whereKey($jobId)->update(['input' => array_merge((array) MlJob::query()->findOrFail($jobId)->input, [
                'audio_disk' => $savedAudio['disk'], 'audio_path' => $savedAudio['path'], 'audio_url' => $audioUrl, 'audio_bytes' => $savedAudio['bytes'],
            ]), 'storage_in_bytes' => (int) $savedAudio['bytes']]);

            $lock = $this->locks->acquireAsrLock((int) $customer->id, $jobId, (string) ($options['input_hash'] ?? ''), request()->hasSession() ? request()->session() : null, request()->userAgent(), request()->ip(), 'caption');
            if (! ($lock['ok'] ?? false)) {
                throw new \RuntimeException((string) ($lock['message'] ?? 'Could not acquire the Caption lock.'));
            }

            MlJob::query()->whereKey($jobId)->update(['submission_attempted_at' => now()]);
            $response = $this->provider->qasr('speech-to-text', 'caption', array_merge($captionOptions, [
                'audio_url' => $audioUrl, 'model_variant' => $model, 'language' => $language, 'type' => 'caption', 'intelligent' => $intelligent ? 1 : 0,
            ]));
            $providerJobId = trim((string) data_get($response, 'id'));
            if ($providerJobId === '') {
                throw new \RuntimeException('RunPod did not return a caption job ID.');
            }
            MlJob::query()->whereKey($jobId)->update(['status' => 'running', 'provider_job_id' => $providerJobId, 'started_at' => now()]);
            $this->workspaceCache->forgetCaptions((int) $customer->id);
        } catch (\Throwable $exception) {
            Log::warning('CAPTION_V2_SUBMISSION_FAILED', ['job_id' => $jobId, 'customer_id' => (int) $customer->id, 'exception' => $exception::class, 'message' => $exception->getMessage()]);
            $this->locks->releaseLock($jobId);
            if ($failedJob = MlJob::find($jobId)) {
                app(DurableUploadSubmission::class)->failed($failedJob, $exception);
            }
            $this->workspaceCache->forgetCaptions((int) $customer->id);
        }

        return MlJob::query()->findOrFail($jobId);
    }
}
