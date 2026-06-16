<?php

namespace App\Services\Mobile;

use App\Models\Voice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MobileTtsVoiceAssetService
{
    protected string $disk = 's3';

    /**
     * @return array{available:bool,file_id:?string,download_endpoint:?string}
     */
    public function previewPayload(Voice $voice): array
    {
        $path = $this->previewPath($voice);

        if ($path === null) {
            return [
                'available' => false,
                'file_id' => null,
                'download_endpoint' => null,
            ];
        }

        return [
            'available' => true,
            'file_id' => basename($path) ?: (string) $voice->code,
            'download_endpoint' => route('api.mobile.tts.voices.preview', ['speakerId' => (string) $voice->code]),
        ];
    }

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

        $filename = basename($path) ?: ((string) $voice->code.'.png');

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
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=600, stale-while-revalidate=60',
        ]);
    }

    public function previewResponse(Voice $voice)
    {
        $path = $this->previewPath($voice);

        abort_if($path === null, 404, 'Preview not available.');

        $stream = Storage::disk($this->disk)->readStream($path);
        abort_unless($stream, 500, 'Unable to open storage stream.');

        $filename = basename($path) ?: ((string) $voice->code.'.wav');

        return response()->stream(function () use ($stream) {
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, 200, [
            'Content-Type' => $this->audioMimeForPath($path),
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=600, stale-while-revalidate=60',
            'Accept-Ranges' => 'bytes',
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

        return 'metkurd_audio_data/'.$normalized;
    }

    public function previewPath(Voice $voice): ?string
    {
        foreach ($this->previewCandidates($voice) as $path) {
            if ($this->pathExists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function previewCandidates(Voice $voice): array
    {
        $meta = (array) ($voice->meta ?? []);
        $engine = strtolower(trim((string) data_get($meta, 'engine')));
        $voiceCode = trim((string) $voice->code);
        $voiceName = trim((string) $voice->name);

        $folder = $this->previewFolderForEngine($engine);

        return collect([
            data_get($meta, 'preview_audio'),
            data_get($meta, 'preview_audio_name'),
            data_get($meta, 'preview_audio_path'),
            data_get($meta, 'preview_key'),
            data_get($meta, 'voice_name'),
            $voiceName,
            $voiceCode,
            $this->previewStemWithoutEnginePrefix($voiceCode),
            $this->normalizePreviewStem($voiceName),
            $this->normalizePreviewStem($voiceCode),
            $this->normalizePreviewStem($this->previewStemWithoutEnginePrefix($voiceCode)),
        ])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->flatMap(fn (string $stem): array => $this->previewPathsFromStem($stem, $folder))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function previewPathsFromStem(string $stem, string $folder): array
    {
        $path = Str::of($stem)
            ->trim()
            ->replace('\\', '/')
            ->ltrim('/')
            ->value();

        if ($path === '') {
            return [];
        }

        $normalized = $this->normalizePreviewStoragePath($path, $folder);

        if ($normalized === '') {
            return [];
        }

        if (pathinfo($normalized, PATHINFO_EXTENSION) !== '') {
            return [$normalized];
        }

        return array_map(
            static fn (string $extension): string => $normalized.'.'.$extension,
            ['mp3', 'm4a', 'wav']
        );
    }

    protected function previewFolderForEngine(string $engine): string
    {
        return match ($engine) {
            'ftts' => 'ftts',
            default => 'xtts',
        };
    }

    protected function previewStemWithoutEnginePrefix(string $voiceCode): string
    {
        return Str::of($voiceCode)
            ->replaceMatches('/^(xtts|ftts)[_-]/i', '')
            ->value();
    }

    protected function normalizePreviewStem(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->replaceMatches('/_+/', '_')
            ->trim('_')
            ->value();
    }

    protected function normalizePreviewStoragePath(string $path, string $folder): string
    {
        if (Str::startsWith($path, 'metkurd_audio_data/')) {
            return $path;
        }

        if (Str::startsWith($path, $folder.'/')) {
            return 'metkurd_audio_data/'.$path;
        }

        return 'metkurd_audio_data/'.$folder.'/'.$path;
    }

    protected function pathExists(string $path): bool
    {
        return Cache::remember(
            'mobile-tts-voice-asset:'.sha1($path),
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

    protected function audioMimeForPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'mp3' => 'audio/mpeg',
            'm4a', 'mp4' => 'audio/mp4',
            'aac' => 'audio/aac',
            'ogg' => 'audio/ogg',
            default => 'audio/wav',
        };
    }

    protected function isExternalUrl(string $value): bool
    {
        return Str::startsWith($value, ['http://', 'https://']);
    }
}
