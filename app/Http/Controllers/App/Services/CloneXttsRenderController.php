<?php

namespace App\Http\Controllers\App\Services;

use App\Http\Controllers\Controller;
use App\Models\MlJob;
use App\Models\Tool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CloneXttsRenderController extends Controller
{
    protected string $toolCode = 'clone_tts';

    protected string $streamFailureLog = 'CLONE_XTTS_STREAM_FAIL';

    protected function jobOrFail(string $jobId): MlJob
    {
        $toolId = Tool::query()->where('code', $this->toolCode)->value('id');

        $job = MlJob::query()
            ->with([
                'tool:id,code,is_active',
                'toolAction:id,tool_code,full_code,is_active',
            ])
            ->where('id', $jobId)
            ->where('customer_id', auth('app')->id())
            ->when($toolId, fn ($q) => $q->where('tool_id', $toolId))
            ->where('status', 'done')
            ->firstOrFail();

        $this->abortUnlessCustomerCanAccessJob($job);

        return $job;
    }

    public function stream(Request $request, string $locale, string $jobId)
    {
        $job = $this->jobOrFail($jobId);
        $disk = (string) data_get($job->output, 'disk', 's3');
        $key = (string) data_get($job->output, 'path', '');
        $mime = (string) data_get($job->output, 'mime', 'audio/wav');
        $filename = basename($key) ?: 'out.wav';

        abort_if($key === '', 404, 'Audio key missing in job output.');

        try {
            abort_unless(Storage::disk($disk)->exists($key), 404, 'Audio file not found on storage.');

            if (! $request->boolean('proxy') && method_exists(Storage::disk($disk), 'temporaryUrl')) {
                $url = Storage::disk($disk)->temporaryUrl($key, now()->addMinutes(20), [
                    'ResponseContentType' => $mime,
                    'ResponseContentDisposition' => 'inline; filename="'.$filename.'"',
                ]);

                return redirect()->away($url);
            }

            $stream = Storage::disk($disk)->readStream($key);
            abort_unless($stream, 500, 'Unable to open audio stream.');

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
                'Cache-Control' => 'private, max-age=600, stale-while-revalidate=60',
                'Accept-Ranges' => 'bytes',
            ]);
        } catch (\Throwable $e) {
            Log::error($this->streamFailureLog, ['job_id' => $jobId, 'message' => $e->getMessage()]);
            abort(500, 'Audio stream failed.');
        }
    }

    public function download(string $locale, string $jobId)
    {
        $job = $this->jobOrFail($jobId);
        $disk = (string) data_get($job->output, 'disk', 's3');
        $key = (string) data_get($job->output, 'path', '');
        $mime = (string) data_get($job->output, 'mime', 'audio/wav');

        abort_if($key === '', 404, 'Audio key missing in job output.');
        abort_unless(Storage::disk($disk)->exists($key), 404, 'Audio file not found on storage.');

        return Storage::disk($disk)->download($key, basename($key) ?: 'out.wav', [
            'Content-Type' => $mime,
        ]);
    }
}
