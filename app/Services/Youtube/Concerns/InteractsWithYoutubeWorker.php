<?php

namespace App\Services\Youtube\Concerns;

use Illuminate\Support\Facades\Log;

trait InteractsWithYoutubeWorker
{
    protected function workerCommand(array $arguments): array
    {
        return [
            $this->pythonBin(),
            '-X',
            'utf8',
            $this->workerScript(),
            ...$arguments,
            ...$this->cookieArguments(),
        ];
    }

    protected function workerScript(): string
    {
        $script = base_path('python/youtube_worker.py');

        if (! is_file($script)) {
            Log::warning('YOUTUBE_WORKER_SCRIPT_MISSING', [
                'path' => $script,
            ]);

            throw new \RuntimeException($this->serviceUnavailableMessage());
        }

        return $script;
    }

    protected function cookieArguments(): array
    {
        $cookieFile = trim((string) config('services.youtube.cookies_file', ''));

        if ($cookieFile !== '') {
            if (
                (str_contains($cookieFile, '\\') || str_contains($cookieFile, '/'))
                && ! is_file($cookieFile)
            ) {
                Log::warning('YOUTUBE_COOKIES_FILE_MISSING', [
                    'path' => $cookieFile,
                ]);

                throw new \RuntimeException($this->serviceUnavailableMessage());
            }

            return ['--cookies-file', $cookieFile];
        }

        $browsers = $this->cookieBrowsers();

        if ($browsers === []) {
            return [];
        }

        $arguments = [];

        foreach ($browsers as $browser) {
            $arguments[] = '--cookies-browser';
            $arguments[] = $browser;
        }

        $profile = trim((string) config('services.youtube.cookies_browser_profile', ''));

        if ($profile !== '') {
            $arguments[] = '--cookies-browser-profile';
            $arguments[] = $profile;
        }

        return $arguments;
    }

    protected function cookieBrowsers(): array
    {
        $configured = config('services.youtube.cookies_browsers', []);
        $browsers = [];

        if (is_array($configured)) {
            foreach ($configured as $browser) {
                $browser = strtolower(trim((string) $browser));

                if ($browser !== '') {
                    $browsers[] = $browser;
                }
            }
        }

        if ($browsers !== []) {
            return array_values(array_unique($browsers));
        }

        $browser = strtolower(trim((string) config('services.youtube.cookies_browser', '')));

        if ($browser === '') {
            return [];
        }

        if ($browser === 'auto') {
            return ['chrome', 'edge', 'opera', 'brave', 'firefox', 'vivaldi', 'chromium'];
        }

        return [$browser];
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
            Log::warning('YOUTUBE_PYTHON_MISSING', [
                'path' => $bin,
                'target' => $target,
            ]);

            throw new \RuntimeException($this->serviceUnavailableMessage());
        }

        return $bin;
    }

    protected function decodeJson(string $raw, string $context): array
    {
        $data = $this->tryDecodeJson($raw);

        if (! is_array($data)) {
            Log::warning('YOUTUBE_WORKER_INVALID_JSON', [
                'context' => $context,
                'raw' => $this->normalizeJsonString($raw),
            ]);

            throw new \RuntimeException($this->unexpectedWorkerResponseMessage());
        }

        return $data;
    }

    protected function logWorkerProcessFailure(string $context, int $exitCode, string $stdout, string $stderr): void
    {
        Log::warning('YOUTUBE_WORKER_PROCESS_FAIL', [
            'context' => $context,
            'exit_code' => $exitCode,
            'stdout' => $this->truncateWorkerOutput($stdout),
            'stderr' => $this->truncateWorkerOutput($stderr),
            'python_target' => config('services.youtube.python_target'),
            'python_bin' => $this->resolvedPythonBinForLogging(),
        ]);
    }

    protected function truncateWorkerOutput(string $value, int $limit = 2000): string
    {
        $value = trim($this->normalizeJsonString($value));

        if ($value === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value, 'UTF-8') <= $limit) {
                return $value;
            }

            return mb_substr($value, 0, $limit, 'UTF-8').'...';
        }

        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit).'...';
    }

    protected function resolvedPythonBinForLogging(): string
    {
        $target = trim((string) config('services.youtube.python_target', 'default'));
        $bin = trim((string) data_get(config('services.youtube.python_bins', []), $target, ''));

        if ($bin === '') {
            $bin = trim((string) config('services.youtube.python_bin', 'python'));
        }

        return $bin !== '' ? $bin : 'python';
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

    protected function friendlyWorkerError(string $message): string
    {
        $normalized = strtolower(trim($message));

        if (
            str_contains($normalized, 'unsupported url')
            || str_contains($normalized, 'invalid url')
            || str_contains($normalized, 'not a valid url')
        ) {
            return 'Please enter a valid YouTube video or playlist URL.';
        }

        if (
            str_contains($normalized, 'video unavailable')
            || str_contains($normalized, 'private video')
            || str_contains($normalized, 'members-only')
            || str_contains($normalized, 'this video is unavailable')
            || str_contains($normalized, 'requested format is not available')
        ) {
            return 'This YouTube media is not currently available in the requested format.';
        }

        if (
            str_contains($normalized, 'sign in to confirm')
            || str_contains($normalized, 'not a bot')
            || str_contains($normalized, 'cookiesfrombrowser')
            || str_contains($normalized, '--cookies-from-browser')
            || str_contains($normalized, 'no usable browser cookies were found')
            || str_contains($normalized, 'failed to decrypt with dpapi')
            || (str_contains($normalized, 'cookies database') && str_contains($normalized, 'could not find'))
            || str_contains($normalized, 'unsupported youtube cookies browser')
        ) {
            return $this->youtubeBlockedMessage();
        }

        if (
            str_contains($normalized, 'python executable not found')
            || str_contains($normalized, 'youtube worker script not found')
            || str_contains($normalized, 'cookies file not found')
            || str_contains($normalized, 'modulenotfounderror')
            || str_contains($normalized, 'no module named')
        ) {
            return $this->serviceUnavailableMessage();
        }

        return 'This YouTube link could not be prepared right now. Please try again later.';
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

    protected function serviceUnavailableMessage(): string
    {
        return 'The YouTube download service is temporarily unavailable. Please try again later.';
    }

    protected function unexpectedWorkerResponseMessage(): string
    {
        return 'The YouTube download service returned an unexpected response. Please try again later.';
    }

    protected function youtubeBlockedMessage(): string
    {
        return 'This YouTube link is currently blocked by YouTube and could not be prepared. Please try another link or try again later.';
    }
}
