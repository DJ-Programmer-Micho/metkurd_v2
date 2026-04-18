<?php

namespace App\Http\Resources\Mobile;

use App\Support\AvatarFallbackUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileCurrentUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing([
            'profile',
            'usage',
            'activeServiceSubscription.servicePlan',
            'activeStorageSubscription.storagePlan',
        ]);

        $profile = $this->resource->profile;
        $displayName = trim((string) (($profile?->first_name ?? '') . ' ' . ($profile?->last_name ?? '')));
        $servicePlan = $this->resource->currentServicePlan();
        $storageState = $this->resource->storageQuotaState();

        return [
            'id' => (int) $this->resource->id,
            'uid' => (string) ($this->resource->uid ?? ''),
            'username' => (string) $this->resource->username,
            'email' => (string) $this->resource->email,
            'display_name' => $displayName !== '' ? $displayName : (string) $this->resource->username,
            'avatar_url' => $profile?->avatar_url ?: app(AvatarFallbackUrl::class)->customer(),
            'verification_complete' => (bool) $this->resource->hasCompletedVerification(),
            'service_plan' => [
                'code' => (string) ($servicePlan?->code ?? 'free'),
                'name' => (string) ($servicePlan?->name ?? 'Free'),
                'is_paid' => (bool) $this->resource->hasPaidServicePlan(),
            ],
            'storage' => [
                'quota_mb' => (int) ($storageState['current_limit_mb'] ?? 512),
                'used_bytes' => (int) ($storageState['used_bytes'] ?? 0),
                'over_quota' => (bool) ($storageState['over_quota'] ?? false),
                'upload_blocked' => (bool) ($storageState['upload_blocked'] ?? false),
            ],
            'accessible_apps' => app(\App\Services\Mobile\MobileAppCatalog::class)->appsForCustomer($this->resource),
            'token' => [
                'abilities' => array_values((array) ($this->resource->currentAccessToken()?->abilities ?? [])),
                'expires_at' => optional($this->resource->currentAccessToken()?->expires_at)->toIso8601String(),
            ],
        ];
    }
}
