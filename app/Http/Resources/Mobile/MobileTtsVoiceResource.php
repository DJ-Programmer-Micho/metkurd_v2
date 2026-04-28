<?php

namespace App\Http\Resources\Mobile;

use App\Services\Mobile\MobileTtsVoiceAssetService;
use App\Services\Mobile\MobileTtsVoiceCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileTtsVoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $meta = (array) ($this->resource->meta ?? []);
        $engine = strtolower(trim((string) data_get($meta, 'engine')));
        $toolCode = app(MobileTtsVoiceCatalog::class)->toolCodeForEngine($engine);
        $assets = app(MobileTtsVoiceAssetService::class);
        $avatar = $assets->avatarPayload($this->resource);
        $preview = $assets->previewPayload($this->resource);
        $languageCodes = data_get($meta, 'language_codes', []);

        if (! is_array($languageCodes)) {
            $languageCodes = [];
        }

        return [
            'speaker_id' => (string) $this->resource->code,
            'tool_code' => $toolCode,
            'engine' => $engine !== '' ? $engine : null,
            'name' => (string) $this->resource->name,
            'description' => $this->nullableString(data_get($meta, 'description')),
            'language_codes' => array_values(array_filter(array_map(
                fn ($value): string => strtolower(trim((string) $value)),
                $languageCodes
            ))),
            'gender' => $this->nullableString(data_get($meta, 'gender')),
            'sort_order' => $this->sortOrder(),
            'is_featured' => (bool) data_get($meta, 'is_featured', false),
            'avatar' => $avatar,
            'preview' => $preview,
        ];
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    protected function sortOrder(): int
    {
        $planSortOrder = (int) ($this->resource->getAttribute('plan_sort_order') ?? 0);

        return $planSortOrder > 0
            ? $planSortOrder
            : (int) ($this->resource->sort_order ?? 0);
    }
}
