<?php

namespace App\Services\Youtube;

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

    public function cleanupLocalTempDir(string $jobId): void
    {
        $dir = $this->localTempDir($jobId);

        if (!is_dir($dir)) {
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
}
