<?php

namespace App\Http\Controllers\App\Services;

use App\Http\Controllers\Controller;
use App\Models\MlJob;
use App\Models\Tool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CaptionRenderController extends Controller
{
    protected function jobOrFail(string $jobId): MlJob
    {
        $toolIds = Tool::query()
            ->whereIn('code', ['caption'])
            ->pluck('id')
            ->all();

        $job = MlJob::query()
            ->with([
                'tool:id,code,is_active',
                'toolAction:id,tool_code,full_code,is_active',
            ])
            ->where('id', $jobId)
            ->where('customer_id', auth('app')->id())
            ->when(! empty($toolIds), fn ($q) => $q->whereIn('tool_id', $toolIds))
            ->where('status', 'done')
            ->firstOrFail();

        $this->abortUnlessCustomerCanAccessJob($job);

        return $job;
    }

    public function downloadTxt(string $locale, string $jobId)
    {
        $job = $this->jobOrFail($jobId);
        $disk = (string) data_get($job->output, 'disk', 's3');
        $key = (string) data_get($job->output, 'path', '');
        $mime = 'text/plain; charset=UTF-8';

        abort_if($key === '', 404, 'Text output missing.');
        abort_unless(Storage::disk($disk)->exists($key), 404, 'Text output not found.');

        return Storage::disk($disk)->download($key, basename($key) ?: 'transcript.txt', [
            'Content-Type' => $mime,
        ]);
    }

    public function downloadSrt(string $locale, string $jobId)
    {
        $job = $this->jobOrFail($jobId);
        $disk = (string) data_get($job->output, 'disk', 's3');
        $key = (string) (
            data_get($job->output, 'srt.path')
            ?: data_get($job->output, 'srt_file.path')
            ?: data_get($job->output, 'srt_path', '')
        );
        $mime = 'application/x-subrip; charset=UTF-8';

        if ($key === '') {
            $inlineSrt = (string) data_get($job->output, 'srt', '');
            abort_if(trim($inlineSrt) === '', 404, 'SRT output missing.');

            return response($inlineSrt, 200, [
                'Content-Type' => $mime,
                'Content-Disposition' => 'attachment; filename="captions.srt"',
            ]);
        }

        abort_unless(Storage::disk($disk)->exists($key), 404, 'SRT output not found.');

        return Storage::disk($disk)->download($key, basename($key) ?: 'captions.srt', [
            'Content-Type' => $mime,
        ]);
    }

    public function downloadJson(string $locale, string $jobId)
    {
        $job = $this->jobOrFail($jobId);
        $mime = 'application/json';

        $json = $this->buildInlineJsonPayload($job);

        return response($json, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="caption.json"',
        ]);
    }

    protected function buildInlineJsonPayload(MlJob $job): string
    {
        $payload = [
            'type' => (string) data_get($job->output, 'type', 'caption'),
            'language' => (string) data_get($job->output, 'language', data_get($job->input, 'language', 'ckb')),
            'text' => (string) data_get($job->output, 'text', ''),
            'srt' => (string) data_get($job->output, 'srt', ''),
            'segments' => array_values((array) data_get($job->output, 'segments', [])),
            'word_count' => (int) data_get($job->output, 'word_count', 0),
            'char_count' => (int) data_get($job->output, 'char_count', 0),
            'meta' => data_get($job->output, 'meta', []),
            'provider_output' => data_get($job->output, 'provider_output', []),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($json) || $json === '') {
            throw new \RuntimeException('Failed to encode Caption JSON payload.');
        }

        return $json;
    }

    public function inputAudio(Request $request, string $locale, string $jobId)
    {
        $job = $this->jobOrFail($jobId);
        $disk = (string) data_get($job->input, 'audio_disk', 's3');
        $key = (string) data_get($job->input, 'audio_path', '');
        $mime = (string) data_get($job->input, 'audio_mime', 'audio/mpeg');

        abort_if($key === '', 404, 'Input audio missing.');

        try {
            if (! $request->boolean('proxy') && method_exists(Storage::disk($disk), 'temporaryUrl')) {
                $url = Storage::disk($disk)->temporaryUrl($key, now()->addMinutes(20), [
                    'ResponseContentType' => $mime,
                    'ResponseContentDisposition' => 'inline; filename="'.(basename($key) ?: 'audio.wav').'"',
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
                'Content-Type' => $mime,
                'Cache-Control' => 'private, max-age=600, stale-while-revalidate=60',
            ]);
        } catch (\Throwable $e) {
            Log::error('CAPTION_INPUT_AUDIO_STREAM_FAIL', [
                'job_id' => $jobId,
                'message' => $e->getMessage(),
            ]);

            abort(500, 'Input audio stream failed.');
        }
    }
}
