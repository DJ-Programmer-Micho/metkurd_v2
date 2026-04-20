<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileAccountUsageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'credits' => [
                'balance' => data_get($this->resource, 'credits.balance'),
                'monthly' => data_get($this->resource, 'credits.monthly'),
                'used' => data_get($this->resource, 'credits.used'),
                'percent_used' => data_get($this->resource, 'credits.percent_used'),
                'percent_remaining' => data_get($this->resource, 'credits.percent_remaining'),
            ],
            'storage' => [
                'used_bytes' => data_get($this->resource, 'storage.used_bytes'),
                'used_mb' => data_get($this->resource, 'storage.used_mb'),
                'quota_bytes' => data_get($this->resource, 'storage.quota_bytes'),
                'quota_mb' => data_get($this->resource, 'storage.quota_mb'),
                'remaining_bytes' => data_get($this->resource, 'storage.remaining_bytes'),
                'remaining_mb' => data_get($this->resource, 'storage.remaining_mb'),
                'percent_used' => data_get($this->resource, 'storage.percent_used'),
                'percent_remaining' => data_get($this->resource, 'storage.percent_remaining'),
                'over_quota' => data_get($this->resource, 'storage.over_quota'),
                'upload_blocked' => data_get($this->resource, 'storage.upload_blocked'),
            ],
            'pricing' => [
                'version' => data_get($this->resource, 'pricing.version'),
                'currency' => data_get($this->resource, 'pricing.currency'),
                'rules' => array_values((array) data_get($this->resource, 'pricing.rules', [])),
            ],
            'meta' => [
                'plan_code' => data_get($this->resource, 'meta.plan_code'),
                'plan_name' => data_get($this->resource, 'meta.plan_name'),
            ],
        ];
    }
}
