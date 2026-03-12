<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Process;

class AudioProbeService
{
    public function probeUploadedFile(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        if (!$path || !is_file($path)) {
            throw new \RuntimeException('Uploaded audio file is missing.');
        }

        return $this->probePath($path, $file->getClientOriginalExtension() ?: 'wav');
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
}