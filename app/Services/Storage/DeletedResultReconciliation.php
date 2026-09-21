<?php

namespace App\Services\Storage;

use App\Models\CustomerFile;
use App\Models\MlJob;
use App\Services\MetKurd\V2\CaptionWorkspaceCache;
use App\Services\MetKurd\V2\CttsWorkspaceCache;
use App\Services\MetKurd\V2\LeoWorkspaceCache;
use Illuminate\Support\Facades\DB;

class DeletedResultReconciliation
{
    private const RESULT_PURPOSES = ['render', 'transcription', 'caption', 'target_text'];

    public function reconcile(CustomerFile $file): void
    {
        $jobId = (string) (data_get($file->meta, 'job_id') ?: ($file->source_type === 'ml_job' ? $file->source_id : ''));
        if ($jobId !== '') {
            DB::transaction(function () use ($file, $jobId) {
                $job = MlJob::query()->where('customer_id', $file->customer_id)->lockForUpdate()->find($jobId);
                if (! $job || $job->status !== 'done') {
                    return;
                }
                if (! in_array($file->purpose, self::RESULT_PURPOSES, true)) {
                    $input = $this->removePath((array) $job->input, $file->path);
                    if (data_get($job->input, 'audio_path') === $file->path) {
                        $input['audio_url'] = null;
                    }
                    if (data_get($job->input, 'file_path') === $file->path) {
                        $input['file_url'] = null;
                    }
                    $job->update(['input' => $input]);

                    return;
                }
                $remaining = CustomerFile::query()->where('customer_id', $file->customer_id)->whereIn('purpose', self::RESULT_PURPOSES)->where('status', 'active')
                    ->where(fn ($q) => $q->where('source_id', $jobId)->orWhere('meta->job_id', $jobId))->get(['path', 'size_bytes']);
                $output = (array) $job->output;
                $output['unavailable_paths'] = array_values(array_unique([...($output['unavailable_paths'] ?? []), $file->path]));
                $job->update(['status' => $remaining->isEmpty() ? 'deleted' : 'done',
                    'output' => $remaining->isEmpty() ? null : $this->removePath($output, $file->path),
                    'storage_out_bytes' => (int) $remaining->sum('size_bytes')]);
            }, 3);
        }
        app(CaptionWorkspaceCache::class)->forgetCaptions((int) $file->customer_id);
        app(LeoWorkspaceCache::class)->forgetTranscriptions((int) $file->customer_id);
        if (in_array($file->tool_code, ['clone_tts', 'clone_xomni', 'vector-v2', 'zeta', 'theta'], true)) {
            app(CttsWorkspaceCache::class)->forgetRenders((int) $file->customer_id, $file->tool_code);
            if ($file->purpose === 'reference') {
                app(CttsWorkspaceCache::class)->forgetReferences((int) $file->customer_id);
            }
        }
    }

    private function removePath(array $output, string $path): array
    {
        foreach ($output as $key => $value) {
            if ($key === 'unavailable_paths') {
                continue;
            }
            if (is_array($value)) {
                $output[$key] = $this->removePath($value, $path);
            } elseif (($key === 'path' || str_ends_with($key, '_path')) && $value === $path) {
                $output[$key] = null;
            }
        }

        return $output;
    }
}
