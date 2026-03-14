<?php

namespace App\Services\Youtube;

use Illuminate\Support\Facades\Process;

class YoutubeDownloadCliService
{
    public function __construct(
        protected YoutubeOutputStorage $storage,
    ) {}

    public function download(
        string $url,
        string $mode,
        string $format,
        string $quality,
        string $jobId
    ): array {
        $script = base_path('python/youtube_worker.py');
        $tempDir = $this->storage->localTempDir($jobId);
        $progressFile = $this->storage->progressSnapshotPath($jobId);
        $progressLog = $this->storage->progressLogPath($jobId);

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        if (! is_file($script)) {
            throw new \RuntimeException("YouTube worker script not found: {$script}");
        }

        $result = Process::path(base_path())
            ->env($this->processEnvironment())
            ->timeout(7500)
            ->run([
                $this->pythonBin(),
                '-X',
                'utf8',
                $script,
                'download',
                '--url',
                trim($url),
                '--mode',
                $mode,
                '--format',
                $format,
                '--quality',
                $quality,
                '--outdir',
                $tempDir,
                '--progress-file',
                $progressFile,
                '--progress-log',
                $progressLog,
            ]);

        $raw = trim($result->output());
        $error = trim($result->errorOutput());

        if (! $result->successful()) {
            $data = $this->tryDecodeJson($raw);

            if (is_array($data) && array_key_exists('error', $data)) {
                throw new \RuntimeException((string) $data['error']);
            }

            throw new \RuntimeException($raw !== '' ? $raw : ($error !== '' ? $error : 'Download command failed.'));
        }

        $data = $this->decodeJson($raw, 'Download');

        if (($data['ok'] ?? false) !== true) {
            throw new \RuntimeException((string) ($data['error'] ?? 'Download failed.'));
        }

        return $data['data'] ?? [];
    }

    protected function pythonBin(): string
    {
        $target = trim((string) config('services.youtube.python_target', 'default'));
        $bin = trim((string) data_get(config('services.youtube.python_bins', []), $target, ''));

        if ($bin === '') {
            $bin = trim((string) config('services.youtube.python_bin', 'python'));
        }

        if ($bin === '') {
            return 'python';
        }

        if (
            (str_contains($bin, '\\') || str_contains($bin, '/'))
            && ! is_file($bin)
        ) {
            throw new \RuntimeException("Python executable not found: {$bin}");
        }

        return $bin;
    }

    protected function decodeJson(string $raw, string $context): array
    {
        $data = $this->tryDecodeJson($raw);

        if (! is_array($data)) {
            throw new \RuntimeException("{$context} returned invalid JSON: ".$this->normalizeJsonString($raw));
        }

        return $data;
    }

    protected function tryDecodeJson(string $raw): ?array
    {
        $normalized = trim($this->normalizeJsonString($raw));

        if ($normalized === '') {
            return null;
        }

        $data = json_decode($normalized, true);

        if (is_array($data)) {
            return $data;
        }

        $lines = preg_split('/\r\n|\n|\r/', $normalized) ?: [];

        foreach (array_reverse($lines) as $line) {
            $candidate = trim((string) $line);

            if ($candidate === '' || ! str_starts_with($candidate, '{')) {
                continue;
            }

            $decoded = json_decode($candidate, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    protected function normalizeJsonString(string $raw): string
    {
        if ($raw === '') {
            return $raw;
        }

        if (function_exists('mb_check_encoding') && ! mb_check_encoding($raw, 'UTF-8')) {
            $converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');

            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return $raw;
    }

    protected function processEnvironment(): array
    {
        $systemRoot = trim((string) (getenv('SYSTEMROOT') ?: getenv('SystemRoot') ?: 'C:\\Windows'));
        $tempDir = trim((string) (getenv('TEMP') ?: getenv('TMP') ?: sys_get_temp_dir()));
        $path = (string) (getenv('PATH') ?: getenv('Path') ?: '');

        return [
            'SYSTEMROOT' => $systemRoot,
            'SystemRoot' => $systemRoot,
            'WINDIR' => trim((string) (getenv('WINDIR') ?: $systemRoot)),
            'ComSpec' => trim((string) (getenv('ComSpec') ?: $systemRoot.'\\System32\\cmd.exe')),
            'TEMP' => $tempDir,
            'TMP' => $tempDir,
            'PATH' => $path,
            'PYTHONUTF8' => '1',
            'PYTHONIOENCODING' => 'utf-8',
        ];
    }
}
