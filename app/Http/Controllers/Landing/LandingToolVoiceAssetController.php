<?php

namespace App\Http\Controllers\Landing;

use App\Http\Controllers\Controller;
use App\Models\Voice;
use App\Services\Mobile\MobileTtsVoiceAssetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LandingToolVoiceAssetController extends Controller
{
    protected string $disk = 's3';

    protected string $defaultAvatarPath = 'metkurd_audio_data/speaker-avatar.png';

    public function __construct(
        protected MobileTtsVoiceAssetService $assets
    ) {}

    public function preview(Request $request, string $locale, string $voiceCode)
    {
        $voice = $this->resolvePublicVoice($voiceCode);
        $path = $this->assets->previewPath($voice);

        abort_if($path === null || ! $this->pathExists($path), 404, 'Preview not available.');

        return $this->respondFromDisk(
            request: $request,
            path: $path,
            mime: $this->audioMimeForPath($path),
            filename: basename($path) ?: ((string) $voice->code.'.wav')
        );
    }

    public function avatar(Request $request, string $locale, string $voiceCode)
    {
        $voice = $this->resolvePublicVoice($voiceCode);
        $path = $this->assets->avatarPath($voice) ?: $this->defaultAvatarPath;

        if ($this->isExternalUrl($path)) {
            return redirect()->away($path);
        }

        if (! $this->pathExists($path)) {
            $path = $this->defaultAvatarPath;
        }

        abort_unless($this->pathExists($path), 404, 'Avatar not available.');

        return $this->respondFromDisk(
            request: $request,
            path: $path,
            mime: $this->mimeForPath($path),
            filename: basename($path) ?: 'speaker-avatar.png'
        );
    }

    protected function resolvePublicVoice(string $voiceCode): Voice
    {
        $voice = Voice::query()->where('code', $voiceCode)->where('is_active', true)->where('is_public', true)
            ->firstOrFail(['id', 'code', 'name', 'meta', 'is_active', 'is_public']);
        // These existing reference previews belong to Apollo 1.5, not Apollo 2 or Zeta output.
        abort_unless(data_get($voice->meta, 'engine') === 'xomni'
            && app(\App\Support\Landing\PublicProductCatalog::class)->has('apollo-1'), 404);

        return $voice;
    }

    protected function respondFromDisk(Request $request, string $path, string $mime, string $filename)
    {
        $disk = Storage::disk($this->disk);
        $cacheControl = 'public, max-age=900, stale-while-revalidate=60';

        if (! $request->boolean('proxy') && method_exists($disk, 'temporaryUrl')) {
            $url = $disk->temporaryUrl($path, now()->addMinutes(20), [
                'ResponseContentType' => $mime,
                'ResponseContentDisposition' => 'inline; filename="'.$filename.'"',
                'ResponseCacheControl' => $cacheControl,
            ]);

            return redirect()->away($url);
        }

        $stream = $disk->readStream($path);
        abort_unless($stream, 500, 'Unable to open storage stream.');

        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => $cacheControl,
            'Accept-Ranges' => 'bytes',
            'X-Content-Type-Options' => 'nosniff',
        ];

        try {
            $size = (int) $disk->size($path);
            if ($size > 0) {
                $headers['Content-Length'] = (string) $size;
            }
        } catch (\Throwable) {
            // Keep streaming even if backend does not expose object size.
        }

        return response()->stream(function () use ($stream) {
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, 200, $headers);
    }

    protected function audioMimeForPath(string $path): string
    {
        try {
            $detected = trim((string) Storage::disk($this->disk)->mimeType($path));
            if (Str::startsWith(Str::lower($detected), 'audio/')) {
                return $detected;
            }
        } catch (\Throwable) {
            // Fall back to extension mapping when object metadata is unavailable.
        }

        return match (Str::lower(pathinfo($path, PATHINFO_EXTENSION))) {
            'mp3' => 'audio/mpeg',
            'm4a', 'mp4' => 'audio/mp4',
            'aac' => 'audio/aac',
            'ogg' => 'audio/ogg',
            default => 'audio/wav',
        };
    }

    protected function mimeForPath(string $path): string
    {
        return match (Str::lower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };
    }

    protected function pathExists(string $path): bool
    {
        return Cache::remember(
            'landing-tool-demo-asset:'.sha1($path),
            now()->addMinutes(10),
            fn (): bool => Storage::disk($this->disk)->exists($path)
        );
    }

    protected function isExternalUrl(string $value): bool
    {
        return Str::startsWith($value, ['http://', 'https://']);
    }
}
