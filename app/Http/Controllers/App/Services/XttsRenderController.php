<?php

namespace App\Http\Controllers\App\Services;

use App\Http\Controllers\Controller;
use App\Models\MlJob;
use App\Models\Tool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class XttsRenderController extends Controller
{
    protected function jobOrFail(string $jobId): MlJob
    {
        $toolId = Tool::where('code', 'tts')->value('id');

        return MlJob::query()
            ->where('id', $jobId)
            ->where('customer_id', auth('app')->id())
            ->when($toolId, fn($q) => $q->where('tool_id', $toolId))
            ->where('status', 'done')
            ->firstOrFail();
    }

    public function stream(Request $request, string $locale, string $jobId)
    {
        $job = $this->jobOrFail($jobId);

        $disk = (string) data_get($job->output, 'disk', 's3');
        $key  = (string) data_get($job->output, 'path', '');

        if ($key === '') {
            abort(404, 'Audio key missing in job output.');
        }

        $mime = (string) data_get($job->output, 'mime', 'audio/wav');
        $filename = basename($key) ?: 'out.wav';

        try {
            if (!Storage::disk($disk)->exists($key)) {
                abort(404, 'Audio file not found on storage.');
            }

            $forceProxy = $request->boolean('proxy');

            if (!$forceProxy && method_exists(Storage::disk($disk), 'temporaryUrl')) {
                $tmpUrl = Storage::disk($disk)->temporaryUrl($key, now()->addMinutes(20), [
                    'ResponseContentType' => $mime,
                    'ResponseContentDisposition' => 'inline; filename="'.$filename.'"',
                ]);

                return redirect()->away($tmpUrl);
            }

            $stream = Storage::disk($disk)->readStream($key);

            if (!$stream) {
                abort(500, 'Unable to open audio stream.');
            }

            return response()->stream(function () use ($stream) {
                try {
                    fpassthru($stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            }, 200, [
                'Content-Type' => $mime,
                'Content-Disposition' => 'inline; filename="'.$filename.'"',
                'Cache-Control' => 'private, max-age=3600',
                'Accept-Ranges' => 'bytes',
            ]);
        } catch (\Throwable $e) {
            Log::error('XTTS_STREAM_500', [
                'job_id' => $jobId,
                'disk' => $disk,
                'key' => $key,
                'message' => $e->getMessage(),
            ]);

            abort(500, 'Audio stream failed.');
        }
    }

    public function download(Request $request, string $locale, string $jobId)
    {
        $job = $this->jobOrFail($jobId);

        $disk = (string) data_get($job->output, 'disk', 's3');
        $key  = (string) data_get($job->output, 'path', '');

        if ($key === '') abort(404, 'Audio key missing in job output.');
        if (!Storage::disk($disk)->exists($key)) abort(404, 'Audio file not found on storage.');

        $mime = (string) data_get($job->output, 'mime', 'audio/wav');
        $filename = basename($key) ?: 'out.wav';

        return Storage::disk($disk)->download($key, $filename, [
            'Content-Type' => $mime,
        ]);
    }
}