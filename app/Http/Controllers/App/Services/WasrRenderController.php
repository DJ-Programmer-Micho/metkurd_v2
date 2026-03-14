<?php

namespace App\Http\Controllers\App\Services;

use App\Http\Controllers\Controller;
use App\Models\MlJob;
use App\Models\Tool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WasrRenderController extends Controller
{
    protected function jobOrFail(string $jobId): MlJob
    {
        $toolIds = Tool::query()
            ->whereIn('code', ['wasr', 'asr'])
            ->pluck('id')
            ->all();

        return MlJob::query()
            ->where('id', $jobId)
            ->where('customer_id', auth('app')->id())
            ->when(!empty($toolIds), fn ($q) => $q->whereIn('tool_id', $toolIds))
            ->where('status', 'done')
            ->firstOrFail();
    }

    public function downloadTxt(string $locale, string $jobId)
    {
        $job  = $this->jobOrFail($jobId);
        $disk = (string) data_get($job->output, 'disk', 's3');
        $key  = (string) data_get($job->output, 'path', '');
        $mime = 'text/plain; charset=UTF-8';

        abort_if($key === '', 404, 'Text output missing.');
        abort_unless(Storage::disk($disk)->exists($key), 404, 'Text output not found.');

        return Storage::disk($disk)->download($key, basename($key) ?: 'transcription.txt', [
            'Content-Type' => $mime,
        ]);
    }

    public function downloadJson(string $locale, string $jobId)
    {
        $job  = $this->jobOrFail($jobId);
        $disk = (string) data_get($job->output, 'disk', 's3');
        $key  = (string) (data_get($job->output, 'json.path') ?: data_get($job->output, 'json_path', ''));
        $mime = (string) (data_get($job->output, 'json.mime') ?: data_get($job->output, 'json_mime', 'application/json'));

        abort_if($key === '', 404, 'JSON output missing.');
        abort_unless(Storage::disk($disk)->exists($key), 404, 'JSON output not found.');

        return Storage::disk($disk)->download($key, basename($key) ?: 'transcription.json', [
            'Content-Type' => $mime,
        ]);
    }

    public function viewJson(Request $request, string $locale, string $jobId)
    {
        $job  = $this->jobOrFail($jobId);
        $disk = (string) data_get($job->output, 'disk', 's3');
        $key  = (string) (data_get($job->output, 'json.path') ?: data_get($job->output, 'json_path', ''));
        $mime = (string) (data_get($job->output, 'json.mime') ?: data_get($job->output, 'json_mime', 'application/json'));

        abort_if($key === '', 404, 'JSON output missing.');

        try {
            if (!$request->boolean('proxy') && method_exists(Storage::disk($disk), 'temporaryUrl')) {
                $url = Storage::disk($disk)->temporaryUrl($key, now()->addMinutes(20), [
                    'ResponseContentType' => $mime,
                    'ResponseContentDisposition' => 'inline; filename="' . (basename($key) ?: 'transcription.json') . '"',
                ]);

                return redirect()->away($url);
            }

            abort_unless(Storage::disk($disk)->exists($key), 404, 'JSON output not found.');
            $stream = Storage::disk($disk)->readStream($key);
            abort_unless($stream, 500, 'Unable to open JSON stream.');

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
                'Cache-Control' => 'private, max-age=600, stale-while-revalidate=60',
            ]);
        } catch (\Throwable $e) {
            Log::error('WASR_JSON_STREAM_FAIL', [
                'job_id' => $jobId,
                'message' => $e->getMessage(),
            ]);

            abort(500, 'JSON stream failed.');
        }
    }

    public function inputAudio(Request $request, string $locale, string $jobId)
    {
        $job  = $this->jobOrFail($jobId);
        $disk = (string) data_get($job->input, 'audio_disk', 's3');
        $key  = (string) data_get($job->input, 'audio_path', '');
        $mime = (string) data_get($job->input, 'audio_mime', 'audio/mpeg');

        abort_if($key === '', 404, 'Input audio missing.');

        try {
            if (!$request->boolean('proxy') && method_exists(Storage::disk($disk), 'temporaryUrl')) {
                $url = Storage::disk($disk)->temporaryUrl($key, now()->addMinutes(20), [
                    'ResponseContentType' => $mime,
                    'ResponseContentDisposition' => 'inline; filename="' . (basename($key) ?: 'input-audio') . '"',
                ]);

                return redirect()->away($url);
            }

            abort_unless(Storage::disk($disk)->exists($key), 404, 'Input audio not found.');

            $stream = Storage::disk($disk)->readStream($key);
            abort_unless($stream, 500, 'Unable to open input audio stream.');

            return response()->stream(function () use ($stream) {
                try {
                    fpassthru($stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            }, 200, [
                'Content-Type'  => $mime,
                'Cache-Control' => 'private, max-age=600, stale-while-revalidate=60',
            ]);
        } catch (\Throwable $e) {
            Log::error('WASR_INPUT_AUDIO_STREAM_FAIL', [
                'job_id'  => $jobId,
                'message' => $e->getMessage(),
            ]);

            abort(500, 'Input audio stream failed.');
        }
    }
}
