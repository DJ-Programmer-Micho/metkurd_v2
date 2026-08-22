<?php

namespace App\Http\Controllers\App\Services;

use App\Http\Controllers\Controller;
use App\Models\CustomerFile;
use Illuminate\Support\Facades\Storage;

/** Same-origin stream for a customer's reusable CTTS reference audio. */
class CttsReferenceStreamController extends Controller
{
    public function __invoke(string $locale, CustomerFile $file)
    {
        abort_unless((int) $file->customer_id === (int) auth('app')->id(), 404);
        abort_unless((string) $file->status === 'active' && (string) $file->purpose === 'reference', 404);
        abort_unless(in_array((string) $file->tool_code, ['clone_tts', 'clone_xomni', 'vector-v2'], true), 404);
        abort_unless((string) data_get($file->meta, 'role', 'speaker_reference') === 'speaker_reference', 404);

        $disk = (string) ($file->disk ?: 's3');
        $path = trim((string) $file->path);
        abort_if($path === '' || ! Storage::disk($disk)->exists($path), 404);

        $stream = Storage::disk($disk)->readStream($path);
        abort_unless($stream, 404);

        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) fclose($stream);
            }
        }, 200, [
            'Content-Type' => (string) ($file->mime ?: 'audio/wav'),
            'Content-Disposition' => 'inline; filename="'.basename($path).'"',
            'Cache-Control' => 'private, max-age=600, stale-while-revalidate=60',
            'Accept-Ranges' => 'bytes',
        ]);
    }
}
