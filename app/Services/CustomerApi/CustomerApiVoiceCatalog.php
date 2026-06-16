<?php

namespace App\Services\CustomerApi;

use App\Models\Customer;
use App\Models\Voice;
use Illuminate\Database\Eloquent\Collection;

class CustomerApiVoiceCatalog
{
    protected const ENGINE_TO_TOOL = [
        'xtts' => [
            'tool_code' => 'tts',
            'action' => 'tts.standard',
        ],
        'ftts' => [
            'tool_code' => 'ftts',
            'action' => 'ftts.standard',
        ],
        'xomni' => [
            'tool_code' => 'xomni',
            'action' => 'xomni.generate',
        ],
    ];

    public function voicesForCustomer(Customer $customer, string $engine): Collection
    {
        $engine = strtolower(trim($engine));
        $mapping = self::ENGINE_TO_TOOL[$engine] ?? null;
        $planId = (int) ($customer->currentServicePlanId() ?? 0);

        if ($mapping === null || $planId <= 0 || ! $customer->isAllowed((string) $mapping['action'], \App\Models\PlanEntitlement::CHANNEL_API)) {
            return new Collection;
        }

        $voices = Voice::query()
            ->select([
                'voices.*',
                'plan_voice_access.sort_order as plan_sort_order',
            ])
            ->join('plan_voice_access', 'plan_voice_access.voice_id', '=', 'voices.id')
            ->where('plan_voice_access.service_plan_id', $planId)
            ->where('plan_voice_access.is_active', true)
            ->where('voices.is_active', true)
            ->where('voices.meta->engine', $engine)
            ->orderBy('plan_voice_access.sort_order')
            ->orderBy('voices.sort_order')
            ->orderBy('voices.name')
            ->get();

        if ($engine !== 'xomni') {
            return $voices;
        }

        return $voices
            ->filter(fn (Voice $voice): bool => trim((string) data_get($voice->meta, 'ref_audio', '')) !== '')
            ->values();
    }

    public function findVoiceForCustomer(Customer $customer, string $engine, string $speakerId): ?Voice
    {
        $speakerId = trim($speakerId);

        if ($speakerId === '') {
            return null;
        }

        return $this->voicesForCustomer($customer, $engine)->firstWhere('code', $speakerId);
    }
}
