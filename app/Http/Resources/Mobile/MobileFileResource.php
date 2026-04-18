<?php

namespace App\Http\Resources\Mobile;

use App\Services\Mobile\MobileAppCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileFileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $appSlug = (string) ($request->route('app') ?: app(MobileAppCatalog::class)->appSlugForFile($this->resource));

        return [
            'id' => (int) $this->resource->id,
            'app' => $appSlug !== '' ? $appSlug : null,
            'tool_code' => (string) ($this->resource->tool_code ?? ''),
            'purpose' => (string) ($this->resource->purpose ?? ''),
            'disk' => (string) ($this->resource->disk ?? 's3'),
            'name' => basename((string) $this->resource->path),
            'path' => (string) $this->resource->path,
            'mime' => (string) ($this->resource->mime ?? 'application/octet-stream'),
            'size_bytes' => (int) ($this->resource->size_bytes ?? 0),
            'created_at' => optional($this->resource->created_at)->toIso8601String(),
            'download_endpoint' => $appSlug !== ''
                ? route('api.mobile.apps.files.download', ['app' => $appSlug, 'fileId' => (int) $this->resource->id])
                : null,
        ];
    }
}
