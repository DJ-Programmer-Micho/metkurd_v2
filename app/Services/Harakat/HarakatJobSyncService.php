<?php

namespace App\Services\Harakat;

use App\Models\MlJob;
use App\Services\MetKurd\Jobs\JobPollCoordinator;
use App\Services\Providers\RunPodProvider;
use App\Services\Storage\CustomerOutputStorage;
use Illuminate\Support\Facades\DB;

class HarakatJobSyncService
{
    public function sync(MlJob $job): array
    {
        abort_unless($job->job_kind === 'harakat', 404);

        return app(JobPollCoordinator::class)->sync($job, fn ($fresh) => $this->poll($fresh), fn ($fresh) => $this->payload($fresh));
    }

    private function poll(MlJob $job): array
    {
        $endpoint = trim((string) config('runpod.endpoints.tashkeel_v1'));
        if ($job->endpoint_key !== 'tashkeel_v1' || $endpoint === '') {
            throw new \RuntimeException('The configured GPU endpoint is unavailable.');
        }
        $response = app(RunPodProvider::class)->status($endpoint, $job->provider_job_id);
        $status = strtoupper((string) ($response['status'] ?? ''));
        if (in_array($status, ['FAILED', 'ERROR', 'CANCELLED', 'CANCELED', 'TIMED_OUT'], true)) {
            return $this->fail($job);
        }
        if (! in_array($status, ['COMPLETED', 'SUCCESS'], true)) {
            $mapped = in_array($status, ['IN_QUEUE', 'QUEUED', 'PENDING', 'THROTTLED', 'THROTTLING', 'NO_CAPACITY', 'NO_WORKERS', 'RATE_LIMITED'], true) ? 'queued' : 'running';
            MlJob::query()->whereKey($job->id)->active()->update(['status' => $mapped]);

            return $this->payload($job->refresh());
        }
        $output = $response['output'] ?? null;
        // SDK errors can be moved to the transport envelope. Never persist raw payloads.
        if (! is_array($output) || ($output['success'] ?? null) !== true || ! empty($response['error']) || ! empty($output['error'])
            || ($output['job_id'] ?? null) !== $job->id || ($output['source_mode'] ?? null) !== 'text'
            || ! is_string($output['text'] ?? null) || trim($output['text']) === '' || ! mb_check_encoding($output['text'], 'UTF-8')
            || mb_strlen($output['text']) > max(1, (int) data_get($job->input, 'characters')) * 4
            || ! is_int($output['chunks'] ?? null) || $output['chunks'] < 1) {
            return $this->fail($job);
        }

        return DB::transaction(function () use ($job, $output) {
            $fresh = MlJob::query()->lockForUpdate()->findOrFail($job->id);
            if (! $fresh->isActive()) {
                return $this->payload($fresh);
            }
            $storage = app(CustomerOutputStorage::class);
            $text = $output['text'];
            $saved = $storage->saveTextToS3((int) $fresh->customer_id, $storage->renderBaseDir($fresh, 'harakat').'/harakat.txt', $text,
                $storage->apiOutputMeta($fresh, 'harakat', 'render', 'diacritized_text') + ['mime' => 'text/plain; charset=UTF-8']);
            $fresh->update([
                'status' => 'done', 'output' => $saved + ['text' => $text, 'characters' => mb_strlen($text),
                    'words' => count(preg_split('/[\s\p{Z}]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY)),
                    'lines' => substr_count($text, "\n") + 1, 'chunks' => $output['chunks'], 'provider_success' => true],
                'storage_out_bytes' => $saved['bytes'], 'finished_at' => now(), 'error' => null, 'failure_stage' => null,
            ]);

            return $this->payload($fresh);
        }, 3);
    }

    private function fail(MlJob $job): array
    {
        MlJob::query()->whereKey($job->id)->active()->update(['status' => 'failed', 'finished_at' => now(),
            'error' => ['code' => 'harakat_failed', 'message' => 'Arabic diacritization could not be completed. Please try again.']]);

        // JobPollCoordinator applies the established durable App refund policy.
        return $this->payload($job->refresh());
    }

    private function payload(MlJob $job): array
    {
        return ['id' => $job->id, 'status' => $job->status];
    }
}
