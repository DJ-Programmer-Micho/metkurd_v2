<?php

namespace App\Services\CustomerApi\V2;

use App\Models\ApiJob;
use App\Models\ApiResultFile;
use App\Models\CustomerFile;
use App\Services\CustomerApi\CustomerApiJobSyncService;

class ApiJobResult
{
    public function payload(ApiJob $job): array
    {
        // Settlement reads application-owned state; status GET never asks the GPU.
        if ($job->ml_job_id) {
            $job = app(CustomerApiJobSyncService::class)->syncFromMlJob($job);
        }
        app(\App\Services\CustomerApi\CustomerApiFileLinkService::class)->attachArtifacts($job);

        return $this->persistedPayload($job);
    }

    /** Pure projection. Only the durable reconciler may advance MCP job state. */
    public function persistedPayload(ApiJob $job): array
    {
        $job->loadMissing('mlJob');
        $status = $job->status === 'accepted' ? 'queued' : $job->status;
        $status = $job->mlJob?->failure_stage === 'provider_submission_unknown' ? 'processing' : $status;
        $payload = ['id' => $job->id, 'status' => $status, 'service' => data_get($job->meta, 'service'),
            'created_at' => $job->created_at->toIso8601String(), 'completed_at' => $job->completed_at?->toIso8601String(),
            'expires_at' => data_get($job->meta, 'expires_at'), 'result' => null];
        if ($status === 'failed') {
            $code = $job->error_code === 'insufficient_credits' ? 'insufficient_credits' : (data_get($job->mlJob?->error, 'code') === 'storage_limit_exceeded' ? 'storage_limit_exceeded' : 'processing_failed');
            $payload['error'] = ['code' => $code, 'message' => \App\Http\Middleware\ApiV2Boundary::MESSAGES[$code]];
        }
        if ($status !== 'completed') {
            return $payload;
        }
        if (data_get($job->meta, 'expires_at') && now()->greaterThanOrEqualTo(\Illuminate\Support\Carbon::parse(data_get($job->meta, 'expires_at')))) {
            $payload['result'] = ['expired' => true, 'files' => []];

            return $payload;
        }
        $files = CustomerFile::query()->where('customer_id', $job->customer_id)->where('status', 'active')->whereNull('deleted_at')
            ->where('meta->job_id', $job->ml_job_id)->whereIn('purpose', ['render', 'transcription', 'caption'])->get();
        $resultFiles = [];
        foreach ($files as $file) {
            if ($file->expires_at && $file->expires_at->lessThanOrEqualTo(now())) {
                continue;
            }
            $link = ApiResultFile::where('api_job_id', $job->id)->where('customer_id', $job->customer_id)
                ->where('storage_file_id', $file->id)->whereIn('result_kind', ['artifact', 'primary'])->whereNull('deleted_at')
                ->orderByRaw("CASE WHEN result_kind = 'artifact' THEN 0 ELSE 1 END")->first();
            if (! $link) {
                continue;
            }
            $resultFiles[] = ['id' => $link->id, 'kind' => data_get($file->meta, 'role') ?: $file->purpose, 'mime_type' => $file->mime,
                'size_bytes' => $file->size_bytes, 'expires_at' => $file->expires_at?->toIso8601String(),
                'download_url' => route('api.customer.v2.files.download', ['id' => $link->id])];
        }
        $output = (array) $job->mlJob?->output;
        $service = data_get($job->meta, 'service');
        $result = ['files' => $resultFiles];
        if (in_array($service, ['transcriptions', 'captions'], true)) {
            $result['text'] = is_string($output['text'] ?? null) ? $output['text'] : '';
        }
        if ($service === 'captions') {
            $result['srt'] = is_string($output['srt'] ?? null) ? $output['srt'] : null;
            // Only public caption fields, never arbitrary worker metadata.
            $result['segments'] = collect($output['segments'] ?? [])->map(fn ($segment) => array_intersect_key((array) $segment, array_flip(['start', 'end', 'text'])))->all();
        }
        if ($service === 'ocr') {
            $result['text'] = (string) data_get($output, 'text.inline', '');
        }
        if ($service === 'harakat' && $resultFiles !== []) {
            $result['text'] = is_string($output['text'] ?? null) ? $output['text'] : '';
            foreach (['characters', 'words', 'lines', 'chunks'] as $field) {
                $result[$field] = (int) ($output[$field] ?? 0);
            }
        }
        if (in_array($service, ['zeta', 'theta'], true)) {
            $result['segment_count'] = (int) data_get($job->mlJob?->input, 'segment_count', 0);
            $result['total_chars'] = (int) data_get($job->mlJob?->input, 'total_chars', 0);
            $duration = (float) ($output['duration'] ?? 0);
            $result['duration'] = is_finite($duration) ? max(0, $duration) : 0;
        }
        $payload['result'] = $result;

        return $payload;
    }
}
