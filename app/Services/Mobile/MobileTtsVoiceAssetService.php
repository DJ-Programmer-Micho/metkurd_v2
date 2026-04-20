<?php

namespace App\Services\Mobile;

use App\Models\Voice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MobileTtsVoiceAssetService
{
    protected string $disk = 's3';

    public function avatarPayload(Voice $voice): ?array
    {
        $path = $this->avatarPath($voice);

        if ($path === null || ! $this->pathExists($path)) {
            return null;
        }

        return [
            'file_name' => basename($path) ?: null,
            'path' => $path,
            'url' => route('api.mobile.tts.voices.avatar', ['speakerId' => (string) $voice->code]),
        ];
    }

    public function avatarResponse(Voice $voice)
    {
        $path = $this->avatarPath($voice);

        abort_if($path === null || ! $this->pathExists($path), 404, 'Avatar not available.');

        $stream = Storage::disk($this->disk)->readStream($path);
        abort_unless($stream, 500, 'Unable to open storage stream.');

        $filename = basename($path) ?: ((string) $voice->code . '.png');

        return response()->stream(function () use ($stream) {
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, 200, [
            'Content-Type' => $this->mimeForPath($path),
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Cache-Control' => 'private, max-age=600, stale-while-revalidate=60',
        ]);
    }

    public function avatarPath(Voice $voice): ?string
    {
        $avatar = trim((string) data_get((array) ($voice->meta ?? []), 'avatar'));

        if ($avatar === '' || $this->isExternalUrl($avatar)) {
            return null;
        }

        $normalized = Str::of($avatar)
            ->replace('\\', '/')
            ->ltrim('/')
            ->value();

        if ($normalized === '') {
            return null;
        }

        if (Str::startsWith($normalized, 'metkurd_audio_data/')) {
            return $normalized;
        }

        return 'metkurd_audio_data/' . $normalized;
    }

    protected function pathExists(string $path): bool
    {
        return Cache::remember(
            'mobile-tts-voice-asset:' . sha1($path),
            now()->addMinutes(10),
            fn (): bool => Storage::disk($this->disk)->exists($path)
        );
    }

    protected function mimeForPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };
    }

    protected function isExternalUrl(string $value): bool
    {
        return Str::startsWith($value, ['http://', 'https://']);
    }
}
