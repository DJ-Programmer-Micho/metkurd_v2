<?php

namespace App\Services\Youtube;

use App\Models\MlJob;
use App\Support\CustomerFolder;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class YoutubeOutputStorage
{
    public function baseTempDir(): string
    {
        $base = trim((string) config('services.youtube.temp_dir', sys_get_temp_dir()));

        if ($base === '') {
            $base = sys_get_temp_dir();
        }

        return rtrim($base, '\\/').DIRECTORY_SEPARATOR.'metkurd-youtube';
    }

    public function localTempDir(string $jobId): string
    {
        return $this->baseTempDir().DIRECTORY_SEPARATOR.$jobId;
    }

    public function progressSnapshotPath(string $jobId): string
    {
        return $this->localTempDir($jobId).DIRECTORY_SEPARATOR.'progress.json';
    }

    public function progressLogPath(string $jobId): string
    {
        return $this->localTempDir($jobId).DIRECTORY_SEPARATOR.'progress.log';
    }

    public function readProgressSnapshot(string $jobId): ?array
    {
        $path = $this->progressSnapshotPath($jobId);

        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    public function readProgressLog(string $jobId, int $limit = 12): array
    {
        $path = $this->progressLogPath($jobId);

        if (! is_file($path)) {
            return [];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (! is_array($lines) || $lines === []) {
            return [];
        }

        $tail = array_slice($lines, -1 * max(1, $limit));

        return array_values(array_filter(array_map(function ($line) {
            $decoded = json_decode((string) $line, true);

            return is_array($decoded) ? $decoded : null;
        }, $tail)));
    }

    public function outputExists(?string $localFilePath): bool
    {
        $path = trim((string) $localFilePath);

        return $path !== '' && is_file($path);
    }

    public function outputBytes(?string $localFilePath): int
    {
        if (! $this->outputExists($localFilePath)) {
            return 0;
        }

        return (int) (filesize((string) $localFilePath) ?: 0);
    }

    public function outputDisk(): string
    {
        return trim((string) config('services.youtube.output_disk', 's3')) ?: 's3';
    }

    public function outputPrefix(): string
    {
        return trim((string) config('services.youtube.output_prefix', 'tmp/youtube-downloads'), '\\/');
    }

    public function outputTtlMinutes(): int
    {
        return max(5, (int) config('services.youtube.output_ttl_minutes', 60));
    }

    public function downloadUrlTtlMinutes(): int
    {
        return max(1, (int) config('services.youtube.download_url_ttl_minutes', 15));
    }

    public function expiresAt(): DateTimeInterface
    {
        return now()->addMinutes($this->outputTtlMinutes());
    }

    public function customerFolder(MlJob $job): string
    {
        $customer = $job->relationLoaded('customer')
            ? $job->customer
            : $job->customer()->with('profile')->first();

        return CustomerFolder::make(
            (int) $job->customer_id,
            data_get($customer, 'profile.first_name') ?? data_get($customer, 'first_name'),
            data_get($customer, 'profile.last_name') ?? data_get($customer, 'last_name'),
            data_get($customer, 'username')
        );
    }

    public function objectKey(MlJob $job, ?string $downloadName = null): string
    {
        $prefix = $this->outputPrefix();
        $folder = $this->customerFolder($job);
        $extension = strtolower((string) pathinfo((string) $downloadName, PATHINFO_EXTENSION));
        $extension = $extension !== '' ? '.'.$extension : '';

        return trim("{$prefix}/{$folder}/{$job->id}/file{$extension}", '/');
    }

    public function uploadLocalOutput(
        MlJob $job,
        string $localPath,
        string $downloadName,
        string $mime
    ): array {
        if (! $this->outputExists($localPath)) {
            throw new \RuntimeException('Finished download file was not found.');
        }

        $disk = $this->outputDisk();
        $path = $this->objectKey($job, $downloadName);
        $stream = fopen($localPath, 'rb');

        if (! is_resource($stream)) {
            throw new \RuntimeException('Could not open the finished download for storage.');
        }

        try {
            Storage::disk($disk)->put($path, $stream, [
                'visibility' => 'private',
                'ContentType' => $mime ?: 'application/octet-stream',
            ]);
        } finally {
            fclose($stream);
        }

        if (! Storage::disk($disk)->exists($path)) {
            throw new \RuntimeException('The finished download could not be stored.');
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'bytes' => (int) Storage::disk($disk)->size($path),
            'mime' => $mime ?: ((string) Storage::disk($disk)->mimeType($path) ?: 'application/octet-stream'),
            'download_name' => $this->downloadName($downloadName, $path),
            'expires_at' => $this->expiresAt(),
        ];
    }

    public function storedOutputExists(string $disk, string $path): bool
    {
        return $path !== '' && Storage::disk($disk)->exists($path);
    }

    public function storedOutputStream(string $disk, string $path)
    {
        return Storage::disk($disk)->readStream($path);
    }

    public function deleteStoredOutput(MlJob $job): void
    {
        $disk = trim((string) data_get($job->output, 'disk', ''));
        $path = trim((string) data_get($job->output, 'path', ''));

        $this->deleteStoredPath($disk, $path);
    }

    public function deleteStoredPath(?string $disk, ?string $path): void
    {
        $disk = trim((string) $disk);
        $path = trim((string) $path);

        if ($disk === '' || $path === '') {
            return;
        }

        if ($this->storedOutputExists($disk, $path)) {
            Storage::disk($disk)->delete($path);
        }
    }

    public function temporaryUrl(string $disk, string $path, string $downloadName, string $mime): ?string
    {
        if ((string) config("filesystems.disks.{$disk}.driver") === 'local') {
            return null;
        }

        $driver = Storage::disk($disk);

        if (! method_exists($driver, 'temporaryUrl')) {
            return null;
        }

        try {
            return $driver->temporaryUrl($path, now()->addMinutes($this->downloadUrlTtlMinutes()), [
                'ResponseContentType' => $mime ?: 'application/octet-stream',
                'ResponseContentDisposition' => 'attachment; filename="'.$this->asciiDownloadName($downloadName).'"',
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    public function isExpired(?MlJob $job): bool
    {
        return $job?->expires_at instanceof Carbon && $job->expires_at->isPast();
    }

    public function cleanupLocalTempDir(string $jobId): void
    {
        $dir = $this->localTempDir($jobId);

        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        @rmdir($dir);
    }

    protected function downloadName(string $downloadName, string $path): string
    {
        $downloadName = trim($downloadName);

        if ($downloadName !== '') {
            return $downloadName;
        }

        return basename($path) ?: 'download.bin';
    }

    protected function asciiDownloadName(string $downloadName): string
    {
        $downloadName = trim($downloadName);

        if ($downloadName === '') {
            return 'download.bin';
        }

        $extension = pathinfo($downloadName, PATHINFO_EXTENSION);
        $name = pathinfo($downloadName, PATHINFO_FILENAME);
        $slug = Str::slug($name, '-');

        if ($slug === '') {
            $slug = 'download';
        }

        return $extension !== ''
            ? "{$slug}.{$extension}"
            : $slug;
    }
}
