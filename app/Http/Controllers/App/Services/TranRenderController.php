<?php

namespace App\Http\Controllers\App\Services;

use App\Http\Controllers\Controller;
use App\Models\MlJob;
use App\Models\Tool;
use Illuminate\Support\Facades\Storage;

class TranRenderController extends Controller
{
    protected function jobOrFail(string $jobId): MlJob
    {
        $toolIds = Tool::query()
            ->whereIn('code', ['tran'])
            ->pluck('id')
            ->all();

        $job = MlJob::query()
            ->with([
                'tool:id,code,is_active',
                'toolAction:id,tool_code,full_code,is_active',
            ])
            ->where('id', $jobId)
            ->where('customer_id', auth('app')->id())
            ->when(!empty($toolIds), fn ($query) => $query->whereIn('tool_id', $toolIds))
            ->where('status', 'done')
            ->firstOrFail();

        $this->abortUnlessCustomerCanAccessJob($job);

        return $job;
    }

    public function downloadSource(string $locale, string $jobId)
    {
        $job = $this->jobOrFail($jobId);
        $disk = (string) data_get($job->input, 'source_disk', 's3');
        $key = (string) data_get($job->input, 'source_path', '');
        $text = (string) data_get($job->input, 'text', '');
        $mime = 'text/plain; charset=UTF-8';

        if ($key !== '' && Storage::disk($disk)->exists($key)) {
            return Storage::disk($disk)->download($key, basename($key) ?: 'source.txt', [
                'Content-Type' => $mime,
            ]);
        }

        abort_if($text === '', 404, 'Source text missing.');

        return response($text, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="source.txt"',
        ]);
    }

    public function downloadTarget(string $locale, string $jobId)
    {
        $job = $this->jobOrFail($jobId);
        $disk = (string) data_get($job->output, 'disk', 's3');
        $key = (string) data_get($job->output, 'path', '');
        $text = (string) data_get($job->output, 'text', '');
        $mime = 'text/plain; charset=UTF-8';

        if ($key !== '' && Storage::disk($disk)->exists($key)) {
            return Storage::disk($disk)->download($key, basename($key) ?: 'target.txt', [
                'Content-Type' => $mime,
            ]);
        }

        abort_if($text === '', 404, 'Translated text missing.');

        return response($text, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="target.txt"',
        ]);
    }
}
