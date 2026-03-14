<?php

namespace App\Services\Youtube;

use Illuminate\Support\Facades\Process;

class YoutubePreviewService
{
    public function preview(string $url): array
    {
        $raw = $this->runWorker([
            'preview',
            '--url',
            trim($url),
        ], 180);

        $data = $this->decodeJson($raw, 'Preview');

        if (($data['ok'] ?? false) !== true) {
            throw new \RuntimeException((string) ($data['error'] ?? 'Preview failed.'));
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

    protected function workerScript(): string
    {
        $script = base_path('python/youtube_worker.py');

        if (! is_file($script)) {
            throw new \RuntimeException("YouTube worker script not found: {$script}");
        }

        return $script;
    }

    protected function runWorker(array $arguments, int $timeoutSeconds): string
    {
        $result = Process::path(base_path())
            ->env($this->processEnvironment())
            ->timeout($timeoutSeconds)
            ->run([
                $this->pythonBin(),
                '-X',
                'utf8',
                $this->workerScript(),
                ...$arguments,
            ]);

        $raw = trim($result->output());
        $error = trim($result->errorOutput());

        if (! $result->successful()) {
            $data = $this->tryDecodeJson($raw);

            if (is_array($data) && array_key_exists('error', $data)) {
                throw new \RuntimeException((string) $data['error']);
            }

            throw new \RuntimeException($raw !== '' ? $raw : ($error !== '' ? $error : 'Preview command failed.'));
        }

        return $raw;
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
        $normalized = $this->normalizeJsonString($raw);
        $data = json_decode($normalized, true);

        return is_array($data) ? $data : null;
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
