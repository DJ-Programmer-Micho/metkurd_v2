<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Resources\Mobile\MobileFileResource;
use App\Services\Mobile\MobileUploadService;
use App\Services\Storage\CustomerOutputStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MobileFilesController extends MobileApiController
{
    public function __construct(
        protected MobileUploadService $uploads,
        protected CustomerOutputStorage $storage,
    ) {}

    public function index(Request $request, string $app)
    {
        $this->appContext($request, $app);

        $files = app(\App\Services\Mobile\MobileAppCatalog::class)
            ->filesQuery($this->customer($request), $app)
            ->orderByDesc('created_at')
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 15))));

        return MobileFileResource::collection($files);
    }

    public function show(Request $request, string $app, int $fileId): MobileFileResource
    {
        $this->appContext($request, $app);

        $file = app(\App\Services\Mobile\MobileAppCatalog::class)
            ->filesQuery($this->customer($request), $app)
            ->where('id', $fileId)
            ->firstOrFail();

        return new MobileFileResource($file);
    }

    public function store(Request $request, string $app): JsonResponse
    {
        $this->appContext($request, $app);
        $catalog = app(\App\Services\Mobile\MobileAppCatalog::class);

        if (! $catalog->uploadEnabled($app)) {
            return response()->json([
                'message' => __('This mobile app does not accept direct file uploads.'),
            ], 405);
        }

        $uploadConfig = $catalog->uploadConfig($app);
        $field = (string) ($uploadConfig['field'] ?? 'file');
        $request->validate([
            $field => (array) ($uploadConfig['rules'] ?? ['required', 'file']),
        ]);

        $file = $this->uploads->store($this->customer($request), $app, $request->file($field));

        return response()->json([
            'message' => __('File uploaded successfully.'),
            'data' => new MobileFileResource($file),
        ], 201);
    }

    public function download(Request $request, string $app, int $fileId): JsonResponse
    {
        $this->appContext($request, $app);

        $file = app(\App\Services\Mobile\MobileAppCatalog::class)
            ->filesQuery($this->customer($request), $app)
            ->where('id', $fileId)
            ->firstOrFail();

        abort_unless(Storage::disk((string) $file->disk)->exists((string) $file->path), 404, 'File not found.');

        $expiresAt = now()->addMinutes(max(1, (int) config('mobile_api.download_url_ttl_minutes', 30)));
        $url = $this->storage->temporaryUrlForDisk((string) $file->disk, (string) $file->path, $expiresAt);

        return response()->json([
            'data' => [
                'file' => new MobileFileResource($file),
                'download_url' => $url,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ]);
    }
}
