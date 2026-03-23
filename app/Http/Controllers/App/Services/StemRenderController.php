<?php

namespace App\Http\Controllers\App\Services;

use App\Http\Controllers\Controller;
use App\Models\MlJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class StemRenderController extends Controller
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
            ->where('job_kind', 'stem')
            ->where('status', 'done')
            ->firstOrFail();

        $this->abortUnlessCustomerCanAccessJob($job);

        return $job;
    }

    protected function resolveTrackPath(MlJob $job, string $track): ?string
    {
        $output = (array) ($job->output ?? []);

        return match ($track) {
            'original' => data_get($output, 'original.path') ?: data_get($job->input, 'audio_path'),
            'vocals' => data_get($output, 'stems.vocals.path'),
            'instrumental' => data_get($output, 'stems.instrumental.path') ?: data_get($output, 'stems.other.path'),
            'drums' => data_get($output, 'stems.drums.path'),
            'bass' => data_get($output, 'stems.bass.path'),
            'other' => data_get($output, 'stems.other.path') ?: data_get($output, 'stems.instrumental.path'),
            default => null,
        };
    }

    protected function resolveTrackMime(MlJob $job, string $track): string
    {
        return match ($track) {
            'original' => (string) (data_get($job->output, 'original.mime') ?: data_get($job->input, 'audio_mime') ?: 'audio/wav'),
            'vocals' => (string) data_get($job->output, 'stems.vocals.mime', 'audio/mpeg'),
            'instrumental' => (string) (data_get($job->output, 'stems.instrumental.mime') ?: data_get($job->output, 'stems.other.mime') ?: 'audio/mpeg'),
            'drums' => (string) data_get($job->output, 'stems.drums.mime', 'audio/mpeg'),
            'bass' => (string) data_get($job->output, 'stems.bass.mime', 'audio/mpeg'),
            'other' => (string) (data_get($job->output, 'stems.other.mime') ?: data_get($job->output, 'stems.instrumental.mime') ?: 'audio/mpeg'),
            default => 'audio/mpeg',
        };
    }

    protected function resolveTrackDisk(MlJob $job, string $track): string
    {
        return $track === 'original'
            ? (string) (data_get($job->input, 'audio_disk') ?: data_get($job->output, 'disk', 's3'))
            : (string) data_get($job->output, 'disk', 's3');
    }

    public function stream(Request $request, string $locale, string $jobId, string $track)
    {
        $job = $this->jobOrFail($jobId);
        $disk = $this->resolveTrackDisk($job, $track);
        $path = (string) $this->resolveTrackPath($job, $track);
        $mime = $this->resolveTrackMime($job, $track);
        $filename = basename($path) ?: "{$track}.mp3";

        abort_if($path === '', 404, 'Audio track missing.');

        try {
            abort_unless(Storage::disk($disk)->exists($path), 404, 'Audio track not found.');

            if (!$request->boolean('proxy') && method_exists(Storage::disk($disk), 'temporaryUrl')) {
                $url = Storage::disk($disk)->temporaryUrl($path, now()->addMinutes(20), [
                    'ResponseContentType' => $mime,
                    'ResponseContentDisposition' => 'inline; filename="' . $filename . '"',
                ]);

                return redirect()->away($url);
            }

            $stream = Storage::disk($disk)->readStream($path);
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
                'Content-Disposition' => 'inline; filename="' . $filename . '"',
                'Cache-Control' => 'private, max-age=600, stale-while-revalidate=60',
                'Accept-Ranges' => 'bytes',
            ]);
        } catch (\Throwable $e) {
            Log::error('STEM_STREAM_FAIL', [
                'job_id' => $jobId,
                'track' => $track,
                'message' => $e->getMessage(),
            ]);

            abort(500, 'Audio stream failed.');
        }
    }

    public function download(string $locale, string $jobId, string $track)
    {
        $job = $this->jobOrFail($jobId);
        $disk = $this->resolveTrackDisk($job, $track);
        $path = (string) $this->resolveTrackPath($job, $track);
        $mime = $this->resolveTrackMime($job, $track);

        abort_if($path === '', 404, 'Audio track missing.');
        abort_unless(Storage::disk($disk)->exists($path), 404, 'Audio track not found.');

        return Storage::disk($disk)->download($path, basename($path) ?: "{$track}.mp3", [
            'Content-Type' => $mime,
        ]);
    }

    public function zip(string $locale, string $jobId)
    {
        $job = $this->jobOrFail($jobId);

        $mode = (int) (data_get($job->meta, 'separation_mode') ?: data_get($job->input, 'stems', 4));
        $tracks = $mode === 2
            ? ['original', 'vocals', 'instrumental']
            : ['original', 'vocals', 'drums', 'bass', 'other'];

        $files = [];
        foreach ($tracks as $track) {
            $path = $this->resolveTrackPath($job, $track);
            $disk = $this->resolveTrackDisk($job, $track);

            if ($path && Storage::disk($disk)->exists($path)) {
                $files[$track . '.' . pathinfo($path, PATHINFO_EXTENSION)] = [
                    'disk' => $disk,
                    'path' => $path,
                ];
            }
        }

        abort_if(empty($files), 404, 'No files available for zip.');

        $tmpZip = tempnam(sys_get_temp_dir(), 'stem_zip_') . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Could not create zip.');
        }

        foreach ($files as $filename => $file) {
            $stream = Storage::disk($file['disk'])->readStream($file['path']);
            if (!$stream) {
                continue;
            }

            try {
                $contents = stream_get_contents($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($contents !== false) {
                $zip->addFromString($filename, $contents);
            }
        }

        $zip->close();

        return response()
            ->download($tmpZip, "stem-{$job->id}.zip")
            ->deleteFileAfterSend(true);
    }
}
