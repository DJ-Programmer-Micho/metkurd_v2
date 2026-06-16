<?php

namespace App\Services\Mobile;

use App\Models\Customer;
use App\Models\Voice;
use Illuminate\Database\Eloquent\Collection;

class MobileTtsVoiceCatalog
{
    /**
     * @var array<string, string>
     */
    protected const TOOL_CODE_TO_ENGINE = [
        'tts' => 'xtts',
        'ftts' => 'ftts',
    ];

    /**
     * @var array<string, string>
     */
    protected const TOOL_CODE_TO_ACTION = [
        'tts' => 'tts.standard',
        'ftts' => 'ftts.standard',
    ];

    public function voicesForCustomer(Customer $customer, ?string $toolCode = null): Collection
    {
        $planId = (int) ($customer->currentServicePlanId() ?? 0);

        if ($planId <= 0) {
            return new Collection;
        }

        $toolCodes = $this->allowedToolCodesForCustomer($customer, $toolCode);

        if ($toolCodes === []) {
            return new Collection;
        }

        $engines = collect($toolCodes)
            ->map(fn (string $code): ?string => $this->engineForToolCode($code))
            ->filter()
            ->values()
            ->all();

        if ($engines === []) {
            return new Collection;
        }

        return Voice::query()
            ->select([
                'voices.*',
                'plan_voice_access.sort_order as plan_sort_order',
            ])
            ->join('plan_voice_access', 'plan_voice_access.voice_id', '=', 'voices.id')
            ->where('plan_voice_access.service_plan_id', $planId)
            ->where('plan_voice_access.is_active', true)
            ->where('voices.is_active', true)
            ->whereIn('voices.meta->engine', $engines)
            ->orderBy('plan_voice_access.sort_order')
            ->orderBy('voices.sort_order')
            ->orderBy('voices.name')
            ->get();
    }

    public function findVoiceForCustomer(Customer $customer, string $speakerId): ?Voice
    {
        $speakerId = trim($speakerId);

        if ($speakerId === '') {
            return null;
        }

        return $this->voicesForCustomer($customer)
            ->firstWhere('code', $speakerId);
    }

    /**
     * @return array<string, string>
     */
    public function speakerOptionsForCustomer(Customer $customer, string $engine): array
    {
        $toolCode = $this->toolCodeForEngine($engine);

        if ($toolCode === null) {
            return [];
        }

        return $this->voicesForCustomer($customer, $toolCode)
            ->mapWithKeys(fn (Voice $voice): array => [
                (string) $voice->code => (string) $voice->name,
            ])
            ->all();
    }

    public function toolCodeForEngine(?string $engine): ?string
    {
        $engine = strtolower(trim((string) $engine));

        if ($engine === '') {
            return null;
        }

        return array_search($engine, self::TOOL_CODE_TO_ENGINE, true) ?: null;
    }

    public function engineForToolCode(?string $toolCode): ?string
    {
        $toolCode = strtolower(trim((string) $toolCode));

        return self::TOOL_CODE_TO_ENGINE[$toolCode] ?? null;
    }

    public function actionCodeForToolCode(?string $toolCode): ?string
    {
        $toolCode = strtolower(trim((string) $toolCode));

        return self::TOOL_CODE_TO_ACTION[$toolCode] ?? null;
    }

    /**
     * @return array<int, string>
     */
    protected function allowedToolCodesForCustomer(Customer $customer, ?string $requestedToolCode = null): array
    {
        $requestedToolCode = strtolower(trim((string) $requestedToolCode));

        return collect(array_keys(self::TOOL_CODE_TO_ENGINE))
            ->when(
                $requestedToolCode !== '',
                fn ($collection) => $collection->filter(fn (string $toolCode): bool => $toolCode === $requestedToolCode)
            )
            ->filter(function (string $toolCode) use ($customer): bool {
                $actionCode = $this->actionCodeForToolCode($toolCode);

                return $actionCode !== null && $customer->isAllowed($actionCode, \App\Models\PlanEntitlement::CHANNEL_MOBILE);
            })
            ->values()
            ->all();
    }
}
