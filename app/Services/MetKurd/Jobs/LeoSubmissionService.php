<?php

namespace App\Services\MetKurd\Jobs;

use App\Models\Customer;
use App\Models\MlJob;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Services\Billing\CreditService;
use App\Services\MetKurd\V2\LeoWorkspaceCache;
use App\Services\MetKurd\V2\RunPodV2Adapter;
use App\Services\Security\JobExecutionLockService;
use App\Services\Storage\CustomerOutputStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Creates isolated V2 Leo ASR jobs while retaining the QASR worker contract. */
class LeoSubmissionService
{
    public function __construct(
        private readonly CreditService $credits,
        private readonly CustomerOutputStorage $storage,
        private readonly RunPodV2Adapter $provider,
        private readonly MlJobRefundService $refunds,
        private readonly JobExecutionLockService $locks,
        private readonly LeoWorkspaceCache $workspaceCache,
    ) {}

    /** @param array{model_variant:string,language:string,intelligent:bool,duration_sec:float,billable_minutes:int,input_hash:string} $options */
    public function submit(Customer $customer, UploadedFile $audio, array $options): MlJob
    {
        $tool = Tool::query()->where('code', 'leo')->firstOrFail();
        $action = ToolAction::query()->where('full_code', 'leo.transcribe')->firstOrFail();
        $minutes = max(0, (int) ($options['billable_minutes'] ?? 0));
        $language = strtolower(trim((string) ($options['language'] ?? 'ckb')));
        $model = trim((string) ($options['model_variant'] ?? 'fine_tuned'));

        if (! $audio->isValid() || $minutes < 1 || $model !== 'fine_tuned' || ! in_array($language, ['ckb', 'ar', 'en'], true)) {
            throw new \InvalidArgumentException('The audio upload or transcription options are invalid.');
        }
        if (method_exists($customer, 'isAllowed') && ! $customer->isAllowed('leo.transcribe')) {
            throw new \RuntimeException('Your plan does not allow Leo transcription.');
        }

        $cost = max(0, (int) $customer->priceCreditsFor('leo.transcribe', [
            'minutes' => $minutes, 'metric_code' => 'minute', 'model_variant' => $model, 'language' => $language,
        ]));
        if ($cost < 1) {
            throw new \RuntimeException('Pricing is not configured for Leo.');
        }

        $jobId = (string) Str::uuid();
        $intelligent = (bool) ($options['intelligent'] ?? false);
        $charged = false;
        $savedAudio = null;

        try {
            DB::transaction(function () use ($customer, $tool, $action, $jobId, $cost, $model, $language, $intelligent, $options): void {
                MlJob::create([
                    'id' => $jobId, 'customer_id' => (int) $customer->id, 'tool_id' => (int) $tool->id, 'tool_action_id' => (int) $action->id,
                    'job_kind' => 'leo', 'status' => 'queued', 'provider' => 'runpod', 'endpoint_key' => 'qasr_v2',
                    'charge_reference' => "ml-job:{$jobId}:charge", 'input_hash' => (string) ($options['input_hash'] ?? ''), 'credits_charged' => $cost,
                    'input' => [
                        'type' => 'asr', 'model_variant' => $model, 'language' => $language, 'intelligent' => $intelligent ? 1 : 0,
                        'audio_name' => $audioName = (string) ($options['audio_name'] ?? 'audio.wav'), 'audio_duration_sec' => (float) ($options['duration_sec'] ?? 0),
                        'audio_billable_min' => (int) ($options['billable_minutes'] ?? 0), 'audio_mime' => (string) ($options['audio_mime'] ?? 'audio/*'),
                    ],
                ]);
            }, 3);

            $charged = $this->credits->charge((int) $customer->id, $cost, 'leo_transcribe_charge', [
                'reference_code' => "ml-job:{$jobId}:charge", 'related_type' => 'ml_job', 'related_id' => $jobId, 'ml_job_id' => $jobId,
                'tool_action' => 'leo.transcribe', 'minutes' => $minutes, 'seconds' => (float) ($options['duration_sec'] ?? 0), 'intelligent' => $intelligent,
            ]);

            $savedAudio = $this->storage->saveUploadedFileToS3((int) $customer->id, $audio, $this->storage->inputPath($customer, 'leo', $jobId, 'wav', 'audio'), [
                'job_id' => $jobId, 'tool' => 'leo', 'purpose' => 'input_audio', 'role' => 'source_audio',
                'checksum' => (string) ($options['input_hash'] ?? ''), 'original_name' => (string) ($options['audio_name'] ?? $audio->getClientOriginalName()),
            ]);
            $audioUrl = $this->storage->temporaryUrl((string) $savedAudio['path'], 120, ['ResponseContentType' => (string) $savedAudio['mime']]);
            MlJob::query()->whereKey($jobId)->update(['input' => array_merge((array) MlJob::query()->findOrFail($jobId)->input, [
                'audio_disk' => $savedAudio['disk'], 'audio_path' => $savedAudio['path'], 'audio_url' => $audioUrl, 'audio_bytes' => $savedAudio['bytes'],
            ]), 'storage_in_bytes' => (int) $savedAudio['bytes']]);

            $lock = $this->locks->acquireAsrLock((int) $customer->id, $jobId, (string) ($options['input_hash'] ?? ''), request()->hasSession() ? request()->session() : null, request()->userAgent(), request()->ip(), 'leo');
            if (! ($lock['ok'] ?? false)) {
                throw new \RuntimeException((string) ($lock['message'] ?? 'Could not acquire the Leo transcription lock.'));
            }

            $response = $this->provider->qasr('speech-to-text', 'leo', [
                'audio_url' => $audioUrl, 'model_variant' => $model, 'language' => $language, 'intelligent' => $intelligent ? 1 : 0,
            ]);
            $providerJobId = trim((string) data_get($response, 'id'));
            if ($providerJobId === '') {
                throw new \RuntimeException('RunPod did not return a transcription job ID.');
            }
            MlJob::query()->whereKey($jobId)->update(['status' => 'running', 'provider_job_id' => $providerJobId, 'started_at' => now()]);
            $this->workspaceCache->forgetTranscriptions((int) $customer->id);
        } catch (\Throwable $exception) {
            Log::warning('LEO_SUBMISSION_FAILED', ['job_id' => $jobId, 'customer_id' => (int) $customer->id, 'exception' => $exception::class, 'message' => $exception->getMessage()]);
            $this->locks->releaseLock($jobId);
            if ($charged) $this->refunds->refundFailedJob($jobId, 'provider_submission');
            MlJob::query()->whereKey($jobId)->update(['status' => 'failed', 'failure_stage' => 'provider_submission', 'error' => ['message' => 'Transcription could not be started. Please try again.'], 'finished_at' => now()]);
            $this->workspaceCache->forgetTranscriptions((int) $customer->id);
        }

        return MlJob::query()->findOrFail($jobId);
    }
}
