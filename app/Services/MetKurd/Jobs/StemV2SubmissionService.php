<?php

namespace App\Services\MetKurd\Jobs;

use App\Models\Customer;
use App\Models\MlJob;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Services\Providers\RunPodProvider;
use App\Services\Security\JobExecutionLockService;
use App\Services\STEM\StemJobSyncService;
use App\Services\Storage\CustomerOutputStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/** Shared V2 STEM submission flow for both two- and four-stem workspaces. */
class StemV2SubmissionService
{
    public function __construct(
        protected DurableUploadSubmission $durable,
        protected CustomerOutputStorage $storage,
        protected JobExecutionLockService $locks,
        protected RunPodProvider $runpod,
        protected StemJobSyncService $sync,
    ) {}

    /**
     * @param  array{stems:int,duration_sec:float,billable_minutes:int,input_hash:string,audio_name:string,audio_mime:string,model?:string,stem_codec?:string,stem_bitrate?:string}  $options
     */
    public function submit(Customer $customer, UploadedFile $audio, array $options, ?SubmissionContext $context = null): MlJob
    {
        $context ??= new SubmissionContext;
        $stems = (int) ($options['stems'] ?? 4) === 2 ? 2 : 4;
        $actionCode = $stems === 2 ? 'sep2' : 'sep4';
        $fullActionCode = "stem.{$actionCode}";
        $duration = max(0, (float) ($options['duration_sec'] ?? 0));
        $minutes = max(0, (int) ($options['billable_minutes'] ?? 0));

        if ($duration <= 0 || $minutes < 1) {
            throw new \RuntimeException(__('Audio duration could not be detected.'));
        }

        if (method_exists($customer, 'isAllowed') && ! $customer->isAllowed($fullActionCode, $context->channel())) {
            throw new \RuntimeException(__('Your plan does not allow this STEM separation mode.'));
        }

        $needed = max(0, (int) $customer->priceCreditsFor($fullActionCode, [
            'channel' => $context->channel(),
            'metric_code' => 'stem_output',
            'outputs' => $stems,
            'stem_outputs' => $stems,
            'separation_mode' => $stems,
            'minutes' => $minutes,
            'seconds' => $duration,
        ]));

        if ($needed <= 0) {
            throw new \RuntimeException(__('Pricing is not configured for this service. Please contact support.'));
        }

        $jobId = (string) Str::uuid();
        $key = (string) ($options['submission_key'] ?? Str::uuid());
        $customer->loadMissing('profile');
        $root = $this->storage->stemV2BaseDir($customer, $stems, $jobId);
        $extension = strtolower((string) ($audio->getClientOriginalExtension() ?: 'wav'));
        $toolId = Tool::query()->where('code', 'stem')->value('id');
        $actionId = ToolAction::query()->where('tool_code', 'stem')->where('action_code', $actionCode)->value('id');

        [$job, $created] = $this->durable->begin((int) $customer->id, $key, $fullActionCode, $needed, 'stem_charge', [
            'outputs' => $stems, 'stem_outputs' => $stems, 'separation_mode' => $stems, 'seconds' => $duration, 'minutes' => $minutes, 'workspace' => 'stem_v2',
        ], function () use ($jobId, $customer, $toolId, $actionId, $needed, $options, $stems, $duration, $minutes, $root, $audio) {
            return MlJob::create([
                'id' => $jobId,
                'customer_id' => (int) $customer->id,
                'tool_id' => $toolId,
                'tool_action_id' => $actionId,
                'job_kind' => 'stem',
                'status' => 'queued',
                'provider' => 'runpod',
                'endpoint_key' => 'stem',
                'input_hash' => (string) ($options['input_hash'] ?? ''),
                'credits_charged' => $needed,
                'input' => [
                    'workspace' => 'stem_v2',
                    'storage_root' => $root,
                    'separation_mode' => $stems,
                    'stems' => $stems,
                    'model' => (string) ($options['model'] ?? 'htdemucs_ft'),
                    'stem_codec' => (string) ($options['stem_codec'] ?? 'mp3'),
                    'stem_bitrate' => (string) ($options['stem_bitrate'] ?? '192k'),
                    'audio_name' => (string) ($options['audio_name'] ?? $audio->getClientOriginalName()),
                    'audio_mime' => (string) ($options['audio_mime'] ?? ($audio->getMimeType() ?: 'audio/*')),
                    'audio_bytes' => (int) $audio->getSize(),
                    'audio_duration_sec' => $duration,
                    'audio_billable_min' => $minutes,
                ],
                'storage_in_bytes' => 0,
                'storage_out_bytes' => 0,
                'started_at' => now(),
            ]);
        }, $context);
        if (! $created) {
            return $job;
        }
        try {

            $savedInput = $this->storage->storeStemV2InputFile($customer, $audio, $stems, $jobId, [
                'checksum' => (string) ($options['input_hash'] ?? ''),
            ], $extension);

            $job = MlJob::query()->findOrFail($jobId);
            $job->update([
                'input' => array_merge((array) $job->input, [
                    'audio_disk' => (string) $savedInput['disk'],
                    'audio_path' => (string) $savedInput['path'],
                    'audio_ext' => $extension,
                ]),
                'storage_in_bytes' => (int) $savedInput['bytes'],
            ]);

            $lock = $this->locks->acquireStemLock(
                customerId: (int) $customer->id,
                jobId: $jobId,
                inputHash: (string) ($options['input_hash'] ?? ''),
                session: (request()->hasSession() ? request()->session() : null),
                agent: request()->userAgent(),
                ip: request()->ip(),
            );
            if (! ($lock['ok'] ?? false)) {
                throw new \RuntimeException((string) ($lock['message'] ?? __('Could not lock the STEM job.')));
            }

            $payload = $this->sync->buildRunpodInput(
                job: $job->fresh(),
                inputDisk: (string) $savedInput['disk'],
                inputPath: (string) $savedInput['path'],
                stems: $stems,
                model: (string) ($options['model'] ?? 'htdemucs_ft'),
                stemCodec: (string) ($options['stem_codec'] ?? 'mp3'),
                stemBitrate: (string) ($options['stem_bitrate'] ?? '192k'),
            );
            $endpointId = (string) (config('runpod.endpoints.stem') ?: env('RUNPOD_ENDPOINT_ID_STEM'));
            if ($endpointId === '') {
                throw new \RuntimeException(__('RUNPOD_ENDPOINT_ID_STEM is missing.'));
            }

            $job->update(['submission_attempted_at' => now(), 'failure_stage' => null]);
            $response = $this->runpod->run($endpointId, $payload);
            $providerJobId = (string) data_get($response, 'id', '');
            if ($providerJobId === '') {
                throw new \RuntimeException('RunPod did not return a provider job ID.');
            }

            $job->update(['provider_job_id' => $providerJobId, 'status' => 'running']);

            return $job->fresh();
        } catch (\Throwable $exception) {
            $result = $this->durable->failed($job, $exception);
            if ($result->status === 'failed') {
                $this->locks->releaseLock($jobId);
            }

            return $result;
        }
    }
}
