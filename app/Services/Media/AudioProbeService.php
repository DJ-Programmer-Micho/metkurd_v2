<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class AudioProbeService
{
    public function probeUploadedFile(UploadedFile $file): array
    {
        $fallbackExt = $this->resolveFallbackExtension($file);
        $localPath = $this->resolveReadableLocalPath($file);

        if ($localPath) {
            return $this->probePath($localPath, $fallbackExt);
        }

        $tempPath = $this->copyUploadedFileToLocalTemp($file, $fallbackExt);

        try {
            return $this->probePath($tempPath, $fallbackExt);
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    public function probePath(string $path, ?string $fallbackExt = null): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Audio file not found: {$path}");
        }

        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '' && $fallbackExt) {
            $ext = strtolower($fallbackExt);
        }

        $process = new Process([
            'ffprobe',
            '-v', 'error',
            '-show_entries', 'format=duration,format_name,size,bit_rate',
            '-show_streams',
            '-of', 'json',
            $path,
        ]);

        $process->setTimeout(25);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Unable to probe audio duration with ffprobe.');
        }

        $json = json_decode($process->getOutput(), true);
        if (!is_array($json)) {
            throw new \RuntimeException('Invalid ffprobe response.');
        }

        $durationSec = (float) data_get($json, 'format.duration', 0);
        $sizeBytes   = (int) data_get($json, 'format.size', 0);
        $bitrate     = (int) data_get($json, 'format.bit_rate', 0);
        $formatName  = (string) data_get($json, 'format.format_name', '');

        $streams = (array) data_get($json, 'streams', []);
        $audioStream = collect($streams)->first(fn ($s) => (string) ($s['codec_type'] ?? '') === 'audio') ?? [];

        $codecName   = (string) ($audioStream['codec_name'] ?? '');
        $sampleRate  = (int) ($audioStream['sample_rate'] ?? 0);
        $channels    = (int) ($audioStream['channels'] ?? 0);

        if ($durationSec <= 0) {
            throw new \RuntimeException('Could not determine audio duration.');
        }

        return [
            'duration_sec'   => round($durationSec, 3),
            'duration_min'   => round($durationSec / 60, 4),
            'billable_min'   => max(1, (int) ceil($durationSec / 60)),
            'size_bytes'     => $sizeBytes,
            'bit_rate'       => $bitrate,
            'format_name'    => $formatName,
            'codec_name'     => $codecName,
            'sample_rate'    => $sampleRate,
            'channels'       => $channels,
            'audio_ext'      => $ext !== '' ? $ext : 'wav',
        ];
    }

    protected function resolveFallbackExtension(UploadedFile $file): string
    {
        $ext = strtolower(trim((string) ($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'wav')));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: '';

        return $ext !== '' ? $ext : 'wav';
    }

    protected function resolveReadableLocalPath(UploadedFile $file): ?string
    {
        $candidates = [];

        foreach (['getRealPath', 'getPathname'] as $method) {
            if (!method_exists($file, $method)) {
                continue;
            }

            try {
                $candidate = $file->{$method}();
            } catch (\Throwable) {
                continue;
            }

            if (is_string($candidate) && $candidate !== '') {
                $candidates[] = $candidate;
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function copyUploadedFileToLocalTemp(UploadedFile $file, string $extension): string
    {
        $source = $this->openUploadedReadStream($file);

        if (!is_resource($source)) {
            throw new \RuntimeException('Uploaded audio file is missing.');
        }

        $tmpBase = tempnam(sys_get_temp_dir(), 'metkurd-audio-probe-');

        if ($tmpBase === false) {
            fclose($source);
            throw new \RuntimeException('Unable to allocate local temporary file for audio probing.');
        }

        $tmpPath = $tmpBase;
        $safeExtension = preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?: '';
        if ($safeExtension !== '') {
            $candidatePath = $tmpBase . '.' . $safeExtension;
            if (@rename($tmpBase, $candidatePath)) {
                $tmpPath = $candidatePath;
            }
        }

        $target = @fopen($tmpPath, 'wb');
        if (!is_resource($target)) {
            fclose($source);
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
            throw new \RuntimeException('Unable to write uploaded audio to local temporary file.');
        }

        $copySucceeded = false;
        try {
            $copied = @stream_copy_to_stream($source, $target);

            if ($copied === false) {
                throw new \RuntimeException('Unable to stream uploaded audio file for probing.');
            }
            $copySucceeded = true;
        } finally {
            fclose($source);
            fclose($target);

            if (!$copySucceeded && is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }

        return $tmpPath;
    }

    protected function openUploadedReadStream(UploadedFile $file)
    {
        if (method_exists($file, 'readStream')) {
            try {
                $stream = $file->readStream();

                if (is_resource($stream)) {
                    return $stream;
                }
            } catch (\Throwable $e) {
                Log::warning('AUDIO_PROBE_READ_STREAM_FAIL', [
                    'file_class' => get_class($file),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $localPath = $this->resolveReadableLocalPath($file);
        if ($localPath) {
            $stream = @fopen($localPath, 'rb');

            if (is_resource($stream)) {
                return $stream;
            }
        }

        return null;
    }
}
