<?php

namespace App\Http\Controllers\Api\Customer\V1;

use App\Models\ApiResultFile;
use App\Services\Storage\CustomerOutputStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileController extends CustomerApiController
{
    public function __construct(
        protected CustomerOutputStorage $storage,
    ) {}

    public function download(Request $request, string $file)
    {
        $result = ApiResultFile::query()
            ->with('storageFile')
            ->where('customer_id', (int) $this->customer($request)->id)
            ->where('id', $file)
            ->first();

        if (! $result instanceof ApiResultFile || $result->deleted_at !== null) {
            return $this->error('File is no longer available.', 'file_unavailable', 404);
        }

        $storageFile = $result->storageFile;

        if ($storageFile === null || (string) $storageFile->status === 'deleted' || $storageFile->deleted_at !== null) {
            return $this->error('File is no longer available.', 'file_unavailable', 410);
        }

        if ($storageFile->expires_at !== null && $storageFile->expires_at->isPast()) {
            return $this->error('File is no longer available.', 'file_expired', 410);
        }

        $disk = (string) ($storageFile->disk ?? 's3');
        $path = (string) ($storageFile->path ?? '');

        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            return $this->error('File is no longer available.', 'file_unavailable', 404);
        }

        $url = $this->storage->temporaryUrlForDisk(
            $disk,
            $path,
            now()->addMinutes((int) config('customer_api.download_url_ttl_minutes', 5))
        );

        return redirect()->away($url);
    }
}
