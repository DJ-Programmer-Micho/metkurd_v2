<?php

namespace App\Services\ASR;

use App\Models\MlJob;
use App\Models\Tool;
use App\Services\Providers\RunPodProvider;
use App\Services\Security\JobExecutionLockService;
use App\Services\Storage\CustomerOutputStorage;
use Illuminate\Support\Facades\DB;

class AsrJobSyncService
{
    public function __construct(
        protected CustomerOutputStorage $storage,
        protected JobExecutionLockService $locks,
    ) {}

    public function sync(MlJob $job, Tool $tool): array
    {
        if (in_array((string) $job->status, ['done', 'failed', 'deleted', 'deleting', 'delete_failed'], true)) {
            return $this->payload($job);
        }

        $endpointId = (string) (
            data_get($tool->meta, 'runpod_endpoint_id')
            ?: config('runpod.endpoints.wasr')
            ?: env('RUNPOD_ENDPOINT_ID_WASR')
        );

        if ($endpointId === '') {
            return $this->failJob($job, 'Missing RunPod WASR endpoint id.');
        }

        $providerJobId = (string) $job->provider_job_id;
        if ($providerJobId === '') {
            return $this->failJob($job, 'Missing provider job id.');
        }

        /** @var RunPodProvider $runpod */
        $runpod = app(RunPodProvider::class);
        $st = $runpod->status($endpointId, $providerJobId);

        $rawStatus = strtoupper((string) data_get($st, 'status', ''));
        $output    = data_get($st, 'output');
        $errMsg    = (string) (data_get($st, 'error') ?: data_get($output, 'error') ?: '');

        $text = $this->extractTranscriptionText($st);
        $chunks = $this->extractChunks($st);
        $progress = $this->extractProgress($st);

        $mapped = match ($rawStatus) {
            'IN_QUEUE', 'QUEUED'                 => 'queued',
            'IN_PROGRESS', 'RUNNING'            => 'running',
            'COMPLETED', 'SUCCESS'              => ($text !== '' ? 'saving' : 'failed'),
            'FAILED', 'CANCELLED', 'TIMED_OUT'  => 'failed',
            default                             => 'running',
        };

        $this->locks->refreshLock((string) $job->id, 60);

        if ($mapped === 'failed' && $text === '') {
            $message = $errMsg !== '' ? $errMsg : (
                in_array($rawStatus, ['COMPLETED', 'SUCCESS'], true)
                    ? 'RunPod completed but returned no transcription text.'
                    : 'RunPod ASR failed.'
            );

            return $this->failJob($job, $message);
        }

        if ($text !== '') {
            return $this->finalizeSuccess($job, $tool, $text, $chunks, $st);
        }

        MlJob::query()->where('id', $job->id)->update([
            'status'     => $mapped,
            'updated_at' => now(),
        ]);

        $job->refresh();

        return $this->payload($job, $progress);
    }

    protected function finalizeSuccess(
        MlJob $job,
        Tool $tool,
        string $transcriptionText,
        array $chunks = [],
        array $providerPayload = []
    ): array {
        return DB::transaction(function () use ($job, $tool, $transcriptionText, $chunks, $providerPayload) {
            $fresh = MlJob::query()->lockForUpdate()->find($job->id);

            if (!$fresh) {
                throw new \RuntimeException('ASR Job not found during finalize.');
            }

            if ((string) $fresh->status === 'done' && data_get($fresh->output, 'path')) {
                return $this->payload($fresh, 100);
            }

            $base   = $this->storage->renderBaseDir($fresh, 'wasr');
            $txtKey = "{$base}/transcription.txt";

            $savedTxt = $this->storage->saveTextToS3(
                (int) $fresh->customer_id,
                $txtKey,
                $transcriptionText,
                [
                    'job_id'  => (string) $fresh->id,
                    'tool'    => (string) ($tool->code ?: 'wasr'),
                    'purpose' => 'transcription',
                    'mime'    => 'text/plain; charset=UTF-8',
                ]
            );

            $charCount = mb_strlen($transcriptionText);
            $wordCount = $this->unicodeWordCount($transcriptionText);
            $providerOutput = data_get($providerPayload, 'output', []);

            $fresh->status = 'done';
            $fresh->output = [
                'disk'       => $savedTxt['disk'],
                'path'       => $savedTxt['path'],
                'bytes'      => $savedTxt['bytes'],
                'mime'       => $savedTxt['mime'],
                'text'       => $transcriptionText,
                'chunks'     => $chunks,
                'char_count' => $charCount,
                'word_count' => $wordCount,
                'provider_output' => $providerOutput,
            ];
            $fresh->storage_out_bytes = (int) $savedTxt['bytes'];
            $fresh->finished_at = now();
            $fresh->error = null;
            $fresh->save();

            $this->locks->releaseLock((string) $fresh->id);

            return $this->payload($fresh, 100);
        }, 3);
    }

    protected function failJob(MlJob $job, string $message): array
    {
        MlJob::query()->where('id', $job->id)->update([
            'status'      => 'failed',
            'error'       => ['message' => $message],
            'finished_at' => now(),
            'updated_at'  => now(),
        ]);

        $this->locks->releaseLock((string) $job->id);

        $job->refresh();

        return $this->payload($job, 100);
    }

    public function deleteFinishedTranscription(MlJob $job): void
    {
        DB::transaction(function () use ($job) {
            $fresh = MlJob::query()->lockForUpdate()->find($job->id);

            if (!$fresh || !in_array((string) $fresh->status, ['done', 'delete_failed'], true)) {
                throw new \RuntimeException('Transcription not found or already deleted.');
            }

            $fresh->status = 'deleting';
            $fresh->error = null;
            $fresh->save();
        }, 3);

        try {
            $fresh = MlJob::query()->findOrFail($job->id);
            $customerId = (int) $fresh->customer_id;

            $txtPath  = (string) data_get($fresh->output, 'path', '');
            $txtBytes = (int) data_get($fresh->output, 'bytes', 0);
            $jsonPath  = (string) data_get($fresh->output, 'json_path', '');
            $jsonBytes = (int) data_get($fresh->output, 'json_bytes', 0);

            if ($txtPath !== '') {
                $this->storage->deleteFromS3AndUncount($customerId, $txtPath, $txtBytes);
            }

            if ($jsonPath !== '') {
                $this->storage->deleteFromS3AndUncount($customerId, $jsonPath, $jsonBytes);
            }

            $audioPath  = (string) data_get($fresh->input, 'audio_path', '');
            $audioBytes = (int) ((int) $fresh->storage_in_bytes ?: data_get($fresh->input, 'audio_bytes', 0));

            if ($audioPath !== '') {
                $this->storage->deleteFromS3AndUncount($customerId, $audioPath, $audioBytes);
            }

            MlJob::query()->where('id', $fresh->id)->update([
                'status'            => 'deleted',
                'output'            => null,
                'storage_in_bytes'  => 0,
                'storage_out_bytes' => 0,
                'error'             => null,
                'updated_at'        => now(),
            ]);

            $this->locks->releaseLock((string) $fresh->id);
        } catch (\Throwable $e) {
            MlJob::query()->where('id', $job->id)->update([
                'status'     => 'delete_failed',
                'error'      => ['message' => $e->getMessage()],
                'updated_at' => now(),
            ]);

            throw $e;
        }
    }

    protected function extractTranscriptionText(array $statusPayload): string
    {
        $output = data_get($statusPayload, 'output');

        $candidates = [
            data_get($statusPayload, 'output.text'),
            data_get($statusPayload, 'output.transcription'),
            data_get($statusPayload, 'output.transcript'),
            data_get($statusPayload, 'output.result.text'),
            data_get($statusPayload, 'text'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        if (is_string($output) && trim($output) !== '') {
            return trim($output);
        }

        return '';
    }

    protected function extractChunks(array $statusPayload): array
    {
        $output = data_get($statusPayload, 'output', []);

        foreach ([
            'chunks',
            'segments',
            'result.chunks',
            'result.segments',
        ] as $key) {
            $value = data_get($output, $key, null);
            if (is_array($value) && !empty($value)) {
                return array_values($value);
            }
        }

        return [];
    }

    protected function extractProgress(array $statusPayload): int
    {
        $progress = data_get($statusPayload, 'output.progress');
        if (is_numeric($progress)) {
            return max(0, min(100, (int) round($progress)));
        }

        return match (strtoupper((string) data_get($statusPayload, 'status', ''))) {
            'IN_QUEUE', 'QUEUED'       => 10,
            'IN_PROGRESS', 'RUNNING'   => 45,
            'COMPLETED', 'SUCCESS'     => 95,
            'FAILED', 'TIMED_OUT'      => 100,
            default                    => 0,
        };
    }

    protected function unicodeWordCount(string $text): int
    {
        preg_match_all('/[\p{L}\p{N}\']+/u', $text, $m);
        return count($m[0] ?? []);
    }

    protected function payload(MlJob $job, int $progress = 0): array
    {
        $status = (string) $job->status;

        return [
            'job_id'   => (string) $job->id,
            'status'   => $status,
            'progress' => $progress ?: match ($status) {
                'queued'         => 10,
                'running'        => 45,
                'saving'         => 90,
                'done', 'failed' => 100,
                default          => 0,
            },
            'done'     => $status === 'done',
            'failed'   => $status === 'failed',
            'message'  => (string) data_get($job->error, 'message', ''),
            'text'     => (string) data_get($job->output, 'text', ''),
        ];
    }
}
