<?php

namespace App\Http\Controllers\App\Services;

use App\Http\Controllers\Controller;
use App\Models\MlJob;
use App\Support\AppRenderPayloads;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class StemRenderController extends Controller
{
    protected int $zipCacheTtlSeconds = 900;

    protected int $zipPruneAfterSeconds = 3600;

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

    /**
     * @return array<int, array{track:string,disk:string,path:string,filename:string}>
     */
    protected function resolveZipEntries(MlJob $job): array
    {
        $mode = (int) (data_get($job->meta, 'separation_mode') ?: data_get($job->input, 'stems', 4));
        $tracks = AppRenderPayloads::stemTracks($mode);
        $entries = [];

        foreach ($tracks as $track) {
            $path = (string) ($this->resolveTrackPath($job, $track) ?? '');
            if ($path === '') {
                continue;
            }

            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            if ($extension === '') {
                $extension = $this->fallbackExtensionForMime($this->resolveTrackMime($job, $track));
            }

            $entries[] = [
                'track' => $track,
                'disk' => $this->resolveTrackDisk($job, $track),
                'path' => $path,
                'filename' => "{$track}.{$extension}",
            ];
        }

        return $entries;
    }

    protected function fallbackExtensionForMime(string $mime): string
    {
        $normalized = strtolower($mime);

        return match (true) {
            str_contains($normalized, 'wav') => 'wav',
            str_contains($normalized, 'flac') => 'flac',
            str_contains($normalized, 'ogg') => 'ogg',
            str_contains($normalized, 'aac') => 'aac',
            str_contains($normalized, 'mp4'),
            str_contains($normalized, 'm4a') => 'm4a',
            default => 'mp3',
        };
    }

    /**
     * @param  array<int, array{track:string,disk:string,path:string,filename:string}>  $entries
     */
    protected function zipSignature(MlJob $job, array $entries): string
    {
        $payload = [
            'job_id' => (string) $job->id,
            'updated_at' => optional($job->updated_at)->timestamp,
            'finished_at' => optional($job->finished_at)->timestamp,
            'storage_out_bytes' => (int) ($job->storage_out_bytes ?? 0),
            'entries' => array_map(static fn (array $entry) => [
                'disk' => (string) $entry['disk'],
                'path' => (string) $entry['path'],
                'filename' => (string) $entry['filename'],
            ], $entries),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return hash('sha256', $json !== false ? $json : serialize($payload));
    }

    protected function zipDirectory(): string
    {
        $directory = storage_path('app/tmp/stem-zips');
        File::ensureDirectoryExists($directory);

        return $directory;
    }

    protected function maybePruneOldZipArtifacts(string $directory): void
    {
        // Run lightweight cleanup occasionally so cached artifacts do not grow forever.
        if (mt_rand(1, 25) !== 1) {
            return;
        }

        $files = glob($directory.DIRECTORY_SEPARATOR.'stem-*.zip');
        if (! is_array($files)) {
            return;
        }

        $expireBefore = time() - max(60, $this->zipPruneAfterSeconds);

        foreach ($files as $path) {
            if (! is_file($path)) {
                continue;
            }

            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $expireBefore) {
                @unlink($path);
            }
        }
    }

    /**
     * @param  array<int, array{track:string,disk:string,path:string,filename:string}>  $entries
     */
    protected function buildZipArchive(string $zipPath, array $entries, string $jobId): int
    {
        $tmpPath = $zipPath.'.tmp';

        if (is_file($tmpPath)) {
            @unlink($tmpPath);
        }

        $zip = new ZipArchive;
        if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return 0;
        }

        $added = 0;

        foreach ($entries as $entry) {
            try {
                if (! Storage::disk($entry['disk'])->exists($entry['path'])) {
                    continue;
                }
            } catch (\Throwable $e) {
                Log::warning('STEM_ZIP_EXISTS_CHECK_FAIL', [
                    'job_id' => $jobId,
                    'disk' => $entry['disk'],
                    'path' => $entry['path'],
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            $stream = null;

            try {
                $stream = Storage::disk($entry['disk'])->readStream($entry['path']);
            } catch (\Throwable $e) {
                Log::warning('STEM_ZIP_READ_STREAM_FAIL', [
                    'job_id' => $jobId,
                    'disk' => $entry['disk'],
                    'path' => $entry['path'],
                    'message' => $e->getMessage(),
                ]);
            }

            if (! $stream) {
                continue;
            }

            try {
                $contents = stream_get_contents($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($contents === false) {
                continue;
            }

            if ($zip->addFromString($entry['filename'], $contents)) {
                $added++;
            }
        }

        $zip->close();

        if ($added === 0) {
            @unlink($tmpPath);

            return 0;
        }

        if (is_file($zipPath)) {
            @unlink($zipPath);
        }

        if (! @rename($tmpPath, $zipPath)) {
            @unlink($tmpPath);

            return 0;
        }

        return $added;
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

            if (! $request->boolean('proxy') && method_exists(Storage::disk($disk), 'temporaryUrl')) {
                $url = Storage::disk($disk)->temporaryUrl($path, now()->addMinutes(20), [
                    'ResponseContentType' => $mime,
                    'ResponseContentDisposition' => 'inline; filename="'.$filename.'"',
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
                'Content-Disposition' => 'inline; filename="'.$filename.'"',
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
        $entries = $this->resolveZipEntries($job);

        abort_if($entries === [], 404, 'No downloadable files are available for this render yet.');

        $signature = $this->zipSignature($job, $entries);
        $cacheKey = "stem-zip-meta:{$job->id}:{$signature}";
        $ttlSeconds = max(60, (int) $this->zipCacheTtlSeconds);
        $zipPath = '';

        $cachedMeta = Cache::get($cacheKey);
        if (is_array($cachedMeta)) {
            $zipPath = (string) ($cachedMeta['path'] ?? '');
        }

        if ($zipPath === '' || ! is_file($zipPath)) {
            $directory = $this->zipDirectory();
            $zipPath = $directory.DIRECTORY_SEPARATOR."stem-{$job->id}-{$signature}.zip";

            if (! is_file($zipPath)) {
                $filesAdded = $this->buildZipArchive($zipPath, $entries, (string) $job->id);
                abort_if($filesAdded === 0, 404, 'No downloadable files are available for this render yet.');
            }

            $this->maybePruneOldZipArtifacts($directory);
        }

        Cache::put($cacheKey, ['path' => $zipPath], now()->addSeconds($ttlSeconds));

        return response()->download($zipPath, "stem-{$job->id}.zip");
    }

    public function payload(string $locale, string $jobId)
    {
        $job = $this->jobOrFail($jobId);

        return response()->json(AppRenderPayloads::stem($job, $locale));
    }
}
