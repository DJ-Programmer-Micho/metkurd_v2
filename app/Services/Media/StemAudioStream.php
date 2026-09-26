<?php

namespace App\Services\Media;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Same-origin STEM playback, with bounded reads and single HTTP byte ranges. */
class StemAudioStream
{
    public function response(Request $request, string $diskName, string $path, string $mime)
    {
        $disk = Storage::disk($diskName);
        abort_unless($disk->exists($path), 404);
        $size = (int) $disk->size($path);
        $headers = [
            'Content-Type' => $mime,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'Content-Length' => (string) $size,
        ];
        // Range applies to GET only. Without a validator, If-Range requests
        // receive the complete representation rather than an unsafe partial one.
        $range = $request->isMethod('GET') && ! $request->hasHeader('If-Range')
            ? $request->header('Range') : null;
        $start = 0;
        $end = $size - 1;
        $partial = false;
        if ($range !== null) {
            // Ignore unsupported/multipart or malformed ranges as permitted by HTTP.
            if (preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $parts) && ($parts[1] !== '' || $parts[2] !== '')) {
                $partial = true;
                if ($parts[1] === '') {
                    $start = max(0, $size - (int) $parts[2]);
                } else {
                    $start = (int) $parts[1];
                    if ($parts[2] !== '') {
                        $end = min($end, (int) $parts[2]);
                    }
                }
                if ($size === 0 || $start > $end || $start >= $size) {
                    return response('', 416, array_replace($headers, [
                        'Content-Range' => 'bytes */'.$size, 'Content-Length' => '0',
                    ]));
                }
            }
        }
        $length = max(0, $end - $start + 1);
        $headers['Content-Length'] = (string) $length;
        if ($partial) {
            $headers['Content-Range'] = "bytes $start-$end/$size";
        }
        if ($request->isMethod('HEAD') || $length === 0) {
            return response('', 200, $headers);
        }

        // S3 gets the requested range upstream too; never buffer/download the
        // full remote object just to serve a seek near its end.
        if ($disk instanceof AwsS3V3Adapter) {
            $arguments = [
                'Bucket' => $disk->getConfig()['bucket'], 'Key' => $disk->path($path),
                '@http' => ['stream' => true],
            ];
            if ($partial) {
                $arguments['Range'] = "bytes=$start-$end";
            }
            $object = $disk->getClient()->getObject($arguments);
            $body = $object['Body'];
            if ($partial && ($object['ContentRange'] ?? null) !== "bytes $start-$end/$size") {
                $body->close();
                abort(502, 'Invalid upstream audio range.');
            }
            $stream = $body->detach();
        } else {
            $stream = $disk->readStream($path);
            abort_unless(is_resource($stream), 500);
            if ($start > 0 && fseek($stream, $start) !== 0) {
                fclose($stream);
                abort(500, 'Unable to seek audio stream.');
            }
        }
        abort_unless(is_resource($stream), 500);

        return response()->stream(function () use ($stream, $length): void {
            try {
                $remaining = $length;
                while ($remaining > 0 && ! feof($stream) && ! connection_aborted()) {
                    $chunk = fread($stream, min(65536, $remaining));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $remaining -= strlen($chunk);
                    echo $chunk;
                }
            } finally {
                fclose($stream);
            }
        }, $partial ? 206 : 200, $headers);
    }
}
