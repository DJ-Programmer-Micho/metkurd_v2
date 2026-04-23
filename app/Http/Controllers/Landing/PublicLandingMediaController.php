<?php

namespace App\Http\Controllers\Landing;

use App\Http\Controllers\Controller;
use App\Support\Landing\LandingMediaStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicLandingMediaController extends Controller
{
    public function __invoke(Request $request, string $path)
    {
        $mediaStorage = app(LandingMediaStorage::class);
        $normalizedPath = $mediaStorage->normalizeStoredPath($path);

        if (! is_string($normalizedPath) || trim($normalizedPath) === '') {
            abort(404);
        }

        if (! $mediaStorage->isAllowedPublicPath($normalizedPath)) {
            abort(404);
        }

        if (! $mediaStorage->exists($normalizedPath)) {
            abort(404);
        }

        $stream = $mediaStorage->readStream($normalizedPath);
        if (! is_resource($stream)) {
            abort(500, 'Unable to read media file.');
        }

        $mime = $mediaStorage->mimeType($normalizedPath) ?: 'application/octet-stream';
        $size = $mediaStorage->size($normalizedPath);
        $lastModified = $mediaStorage->lastModified($normalizedPath);
        $maxAge = $mediaStorage->maxAge();
        $etag = sha1($normalizedPath . '|' . ($size ?? '0') . '|' . ($lastModified ?? '0'));
        $etagHeader = '"' . $etag . '"';

        if (trim((string) $request->headers->get('If-None-Match', '')) === $etagHeader) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            return response('', Response::HTTP_NOT_MODIFIED, [
                'ETag' => $etagHeader,
                'Cache-Control' => 'public, max-age=' . $maxAge . ', stale-while-revalidate=60',
            ]);
        }

        $headers = [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=' . $maxAge . ', stale-while-revalidate=60',
            'ETag' => $etagHeader,
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (is_int($size) && $size >= 0) {
            $headers['Content-Length'] = (string) $size;
        }

        if (is_int($lastModified) && $lastModified > 0) {
            $headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $lastModified) . ' GMT';
        }

        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, Response::HTTP_OK, $headers);
    }
}
