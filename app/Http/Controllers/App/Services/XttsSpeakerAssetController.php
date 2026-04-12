<?php

namespace App\Http\Controllers\App\Services;

use App\Http\Controllers\Controller;
use App\Models\Voice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class XttsSpeakerAssetController extends Controller
{
    public function preview(Request $request, string $locale, string $voiceCode)
    {
        $voice = $this->voiceAssetData($voiceCode);

        foreach ($this->previewCandidates($voice) as $target) {
            if ($this->isExternalUrl($target)) {
                return redirect()->away($target);
            }

            if (!Storage::disk('s3')->exists($target)) {
                continue;
            }

            return $this->respondFromDisk(
                request: $request,
                path: $target,
                mime: 'audio/wav',
                filename: basename($target) ?: "{$voiceCode}.wav"
            );
        }

        abort(404, 'Preview not available.');
    }

    public function avatar(Request $request, string $locale, string $voiceCode)
    {
        $voice = $this->voiceAssetData($voiceCode);
        $target = $this->avatarTarget($voice) ?: 'metkurd_audio_data/speaker-avatar.png';

        if ($this->isExternalUrl($target)) {
            return redirect()->away($target);
        }

        if (!Storage::disk('s3')->exists($target)) {
            // Log::warning('Speaker avatar not found on disk', ['target' => $target, 'voice_code' => $voiceCode]);
            $target = 'metkurd_audio_data/speaker-avatar.png';
        }

        abort_unless(Storage::disk('s3')->exists($target), 404, 'Avatar not available.');

        return $this->respondFromDisk(
            request: $request,
            path: $target,
            mime: $this->mimeForPath($target),
            filename: basename($target) ?: 'speaker-avatar.png'
        );
    }

    protected function voiceAssetData(string $voiceCode): array
    {
        abort_unless(auth('app')->check(), 404);

        return cache()->remember(
            'xtts-speaker-asset:' . $voiceCode,
            now()->addMinutes(15),
            function () use ($voiceCode): array {
                return Voice::query()
                    ->where('code', $voiceCode)
                    ->where('is_active', true)
                    ->firstOrFail(['code', 'name', 'meta'])
                    ->toArray();
            }
        );
    }

    protected function previewCandidates(array $voice): array
    {
        $meta = (array) ($voice['meta'] ?? []);
        $voiceName = trim((string) ($voice['name'] ?? $voice['code'] ?? ''));

        return collect([
            data_get($meta, 'preview_audio'),
            data_get($meta, 'preview_audio_name'),
            data_get($meta, 'preview_audio_path'),
            data_get($meta, 'preview_key'),
            data_get($meta, 'voice_name'),
            $voiceName,
            (string) ($voice['code'] ?? ''),
            $this->normalizePreviewStem($voiceName),
            $this->normalizePreviewStem((string) ($voice['code'] ?? '')),
        ])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->map(fn (string $stem) => $this->previewPathFromStem($stem))
            ->filter()
            ->values()
            ->all();
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

    protected function previewPathFromStem(string $stem): ?string
    {
        $path = Str::of($stem)
            ->trim()
            ->replace('\\', '/')
            ->ltrim('/')
            ->value();

        if ($path === '') {
            return null;
        }

        if ($this->isExternalUrl($path)) {
            return $path;
        }

        if (!Str::endsWith(strtolower($path), '.wav')) {
            $path .= '.wav';
        }

        if (Str::startsWith($path, 'metkurd_audio_data/')) {
            return $path;
        }

        if (Str::startsWith($path, 'xtts/')) {
            return 'metkurd_audio_data/' . $path;
        }

        return 'metkurd_audio_data/xtts/' . $path;
    }

    protected function avatarTarget(array $voice): ?string
    {
        $avatar = Str::of((string) data_get((array) ($voice['meta'] ?? []), 'avatar'))
            ->trim()
            ->replace('\\', '/')
            ->ltrim('/')
            ->value();

        // Log::info('Determining avatar target for voice', ['voice_code' => $voice['code'] ?? null, 'avatar_meta' => $avatar]);    
        if ($avatar === '') {
            // Log::info('No avatar specified for voice', ['voice_code' => $voice['code'] ?? null]);
            return 'metkurd_audio_data/speaker-avatar.png';
        }

        if ($this->isExternalUrl($avatar) || Str::startsWith($avatar, 'metkurd_audio_data/')) {
            return $avatar;
        }

        return 'metkurd_audio_data/' . $avatar;
    }

    protected function respondFromDisk(Request $request, string $path, string $mime, string $filename)
    {
        $disk = Storage::disk('s3');

        if (!$request->boolean('proxy') && method_exists($disk, 'temporaryUrl')) {
            $url = $disk->temporaryUrl($path, now()->addMinutes(20), [
                'ResponseContentType' => $mime,
                'ResponseContentDisposition' => 'inline; filename="' . $filename . '"',
            ]);

            return redirect()->away($url);
        }

        $stream = $disk->readStream($path);
        abort_unless($stream, 500, 'Unable to open storage stream.');

        return response()->stream(function () use ($stream) {
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Cache-Control' => 'private, max-age=600, stale-while-revalidate=60',
            'Accept-Ranges' => 'bytes',
        ]);
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
