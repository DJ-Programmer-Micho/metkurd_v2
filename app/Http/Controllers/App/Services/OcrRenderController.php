<?php

namespace App\Http\Controllers\App\Services;

use App\Http\Controllers\Controller;
use App\Models\MlJob;
use App\Support\AppRenderPayloads;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OcrRenderController extends Controller
{
    protected function jobOrFail(string $jobId): MlJob
    {
        $job = MlJob::query()
            ->with([
                'tool:id,code,is_active',
                'toolAction:id,tool_code,full_code,is_active',
            ])
            ->where('id', $jobId)
            ->where('customer_id', auth('app')->id())
            ->where('job_kind', 'ocr')
            ->where('status', 'done')
            ->firstOrFail();

        $this->abortUnlessCustomerCanAccessJob($job);

        return $job;
    }

    public function downloadText(string $locale, string $jobId)
    {
        $job = $this->jobOrFail($jobId);
        $disk = (string) data_get($job->output, 'disk', 's3');
        $path = (string) data_get($job->output, 'text.path', '');

        abort_if($path === '', 404, 'Text output missing.');
        abort_unless(Storage::disk($disk)->exists($path), 404, 'Text output not found.');

        return Storage::disk($disk)->download($path, basename($path) ?: 'ocr.txt', [
            'Content-Type' => (string) data_get($job->output, 'text.mime', 'text/plain; charset=UTF-8'),
        ]);
    }

    public function viewText(Request $request, string $locale, string $jobId)
    {
        $job = $this->jobOrFail($jobId);
        $disk = (string) data_get($job->output, 'disk', 's3');
        $path = (string) data_get($job->output, 'text.path', '');
        $mime = (string) data_get($job->output, 'text.mime', 'text/plain; charset=UTF-8');

        abort_if($path === '', 404, 'Text output missing.');

        try {
            if (! $request->boolean('proxy') && method_exists(Storage::disk($disk), 'temporaryUrl')) {
                $url = Storage::disk($disk)->temporaryUrl($path, now()->addMinutes(20), [
                    'ResponseContentType' => $mime,
                    'ResponseContentDisposition' => 'inline; filename="'.(basename($path) ?: 'ocr.txt').'"',
                ]);

                return redirect()->away($url);
            }

            abort_unless(Storage::disk($disk)->exists($path), 404, 'Text output not found.');
            $stream = Storage::disk($disk)->readStream($path);
            abort_unless($stream, 500, 'Unable to open text stream.');

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
            Log::error('OCR_TEXT_STREAM_FAIL', [
                'job_id' => $jobId,
                'message' => $e->getMessage(),
            ]);

            abort(500, 'Text stream failed.');
        }
    }

    public function downloadJson(string $locale, string $jobId)
    {
        $job = $this->jobOrFail($jobId);
        $disk = (string) data_get($job->output, 'disk', 's3');
        $path = (string) data_get($job->output, 'json.path', '');

        abort_if($path === '', 404, 'JSON output missing.');
        abort_unless(Storage::disk($disk)->exists($path), 404, 'JSON output not found.');

        return Storage::disk($disk)->download($path, basename($path) ?: 'ocr.json', [
            'Content-Type' => (string) data_get($job->output, 'json.mime', 'application/json'),
        ]);
    }

    public function downloadArtifact(string $locale, string $jobId, string $format)
    {
        abort_unless(in_array($format, ['docx', 'markdown', 'html', 'zip'], true), 404);
        $job = $this->jobOrFail($jobId);
        $disk = (string) data_get($job->output, 'disk', 's3');
        $path = (string) data_get($job->output, "artifacts.{$format}.path", '');
        abort_if($path === '' || ! Storage::disk($disk)->exists($path), 404, 'OCR output not found.');
        $mime = (string) data_get($job->output, "artifacts.{$format}.mime", 'application/octet-stream');

        return Storage::disk($disk)->download($path, basename($path) ?: "ocr.{$format}", ['Content-Type' => $mime]);
    }

    public function viewJson(Request $request, string $locale, string $jobId)
    {
        $job = $this->jobOrFail($jobId);
        $disk = (string) data_get($job->output, 'disk', 's3');
        $path = (string) data_get($job->output, 'json.path', '');
        $mime = (string) data_get($job->output, 'json.mime', 'application/json');

        abort_if($path === '', 404, 'JSON output missing.');

        try {
            if (! $request->boolean('proxy') && method_exists(Storage::disk($disk), 'temporaryUrl')) {
                $url = Storage::disk($disk)->temporaryUrl($path, now()->addMinutes(20), [
                    'ResponseContentType' => $mime,
                    'ResponseContentDisposition' => 'inline; filename="'.(basename($path) ?: 'ocr.json').'"',
                ]);

                return redirect()->away($url);
            }

            abort_unless(Storage::disk($disk)->exists($path), 404, 'JSON output not found.');
            $stream = Storage::disk($disk)->readStream($path);
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
            Log::error('OCR_JSON_STREAM_FAIL', [
                'job_id' => $jobId,
                'message' => $e->getMessage(),
            ]);

            abort(500, 'JSON stream failed.');
        }
    }

    public function inputDocument(Request $request, string $locale, string $jobId)
    {
        $job = $this->jobOrFail($jobId);
        $disk = (string) data_get($job->input, 'file_disk', 's3');
        $path = (string) data_get($job->input, 'file_path', '');
        $mime = (string) data_get($job->input, 'file_mime', 'application/pdf');

        abort_if($path === '', 404, 'Input document missing.');

        try {
            if (! $request->boolean('proxy') && method_exists(Storage::disk($disk), 'temporaryUrl')) {
                $url = Storage::disk($disk)->temporaryUrl($path, now()->addMinutes(20), [
                    'ResponseContentType' => $mime,
                    'ResponseContentDisposition' => 'inline; filename="'.(basename($path) ?: 'input').'"',
                ]);

                return redirect()->away($url);
            }

            abort_unless(Storage::disk($disk)->exists($path), 404, 'Input document not found.');
            $stream = Storage::disk($disk)->readStream($path);
            abort_unless($stream, 500, 'Unable to open input document stream.');

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
            Log::error('OCR_INPUT_STREAM_FAIL', [
                'job_id' => $jobId,
                'message' => $e->getMessage(),
            ]);

            abort(500, 'Input document stream failed.');
        }
    }

    public function payload(string $locale, string $jobId)
    {
        $job = $this->jobOrFail($jobId);

        return response()->json(AppRenderPayloads::ocr($job, $locale));
    }
}
