<?php

namespace App\Support\Admin;

use App\Models\PlanEntitlement;
use App\Models\ServicePlan;
use App\Models\ToolAction;
use App\Models\Voice;
use App\Services\Admin\AdminV2Catalog;
use App\Services\CustomerApi\CustomerApiAccessService;
use App\Services\CustomerApi\V2\ApiCatalog;
use App\Services\MetKurd\Omni\OmniSpeakerCatalog;
use App\Services\MetKurd\V2\InputBoundary;
use App\Support\Landing\PublicProductCatalog;

/** Read-only Admin presentation; runtime resolvers remain the access/limit authority. */
final class AdminServiceWorkspace
{
    public static function actionName(?ToolAction $action): string
    {
        foreach (app(ApiCatalog::class)->variants() as $variant) {
            if ($variant['action'] === $action?->full_code) {
                return $variant['tool']['name'];
            }
        }

        return $action?->name ?? __('Unknown Action');
    }

    public static function family(string $webService): string
    {
        return __('admin_service.family_'.match ($webService) {
            'text-to-speech' => 'tts', 'clone-text-to-speech' => 'ctts',
            'speech-to-text' => 'asr', 'ocr' => 'ocr', 'stem' => 'stem',
            default => 'legacy',
        });
    }

    public function publicStatus(string $action): string
    {
        if (! collect(app(ApiCatalog::class)->variants())->contains('action', $action)) {
            return 'legacy';
        }

        return collect(app(PublicProductCatalog::class)->products())->contains('action', $action) ? 'public' : 'hidden';
    }

    public function matrix(string $channel, ?int $planId = null): array
    {
        AdminAccess::authorize('admin.read');
        $channel = in_array($channel, ['app', 'api'], true) ? $channel : 'app';
        $plans = ServicePlan::orderBy('sort_order')->orderBy('id')->when($planId, fn ($q) => $q->whereKey($planId))->get();
        $variants = app(ApiCatalog::class)->variants();
        $actions = ToolAction::with('tool')->whereIn('full_code', array_column($variants, 'action'))->get()->keyBy('full_code');
        $entitlements = PlanEntitlement::whereIn('service_plan_id', $plans->modelKeys())
            ->whereIn('entitlement_channel', PlanEntitlement::fallbackChannels($channel, true))->get();
        $previews = $plans->mapWithKeys(fn ($plan) => [$plan->id => app(AdminV2Catalog::class)->planCustomer($plan)]);
        $rows = [];
        foreach ($variants as $variant) {
            $action = $actions->get($variant['action']);
            $cells = [];
            foreach ($plans as $plan) {
                // Provenance only. Effective access and character limits come from runtime.
                $candidates = $entitlements->where('service_plan_id', $plan->id)->where('tool_action_id', $action?->id);
                $source = $candidates->firstWhere('entitlement_channel', $channel) ?? $candidates->firstWhere('entitlement_channel', 'all');
                $preview = $previews[$plan->id];
                $characters = in_array($variant['tool']['kind'] ?? '', ['omni_tts', 'omni_clone'], true);
                $cells[$plan->id] = [
                    'id' => $source?->id, 'source' => $source?->entitlement_channel,
                    'decision' => $source ? ($source->allowed ? 'allow' : 'deny') : 'missing',
                    'effective' => $preview->isAllowed($variant['action'], $channel),
                    'characters' => $characters ? app(InputBoundary::class)->characterLimit($preview, $variant['action'], $channel) : null,
                    'configured_characters' => $characters ? $preview->inputLimitFor($variant['action'], 'max_chars_per_submit', $channel) : null,
                ];
            }
            $rows[] = ['action_id' => $action?->id, 'action' => $variant['action'], 'name' => $variant['tool']['name'],
                'family' => self::family($variant['web_service']), 'active' => (bool) $action?->is_active && (bool) $action?->tool?->is_active,
                'cells' => $cells];
        }

        return ['plans' => $plans, 'rows' => $rows, 'channel' => $channel];
    }

    public function planSummary(ServicePlan $plan): array
    {
        AdminAccess::authorize('admin.read');
        $preview = app(AdminV2Catalog::class)->planCustomer($plan);
        $catalog = app(ApiCatalog::class);
        $api = app(CustomerApiAccessService::class)->configForPlan($plan);
        $scopes = $catalog->scopesForConfiguration($api['allowed_tools']);
        $families = ['app' => [], 'api' => []];
        $limits = [];
        foreach ($catalog->variants() as $variant) {
            foreach (['app', 'api'] as $channel) {
                $allowed = $preview->isAllowed($variant['action'], $channel);
                if ($channel === 'api') {
                    $allowed = $allowed && $api['api_enabled'] && $api['requests_per_minute'] > 0
                        && $api['concurrent_jobs'] > 0 && in_array($variant['scope'], $scopes, true);
                }
                if ($allowed) {
                    $families[$channel][] = self::family($variant['web_service']);
                }
                if (in_array($variant['tool']['kind'] ?? '', ['omni_tts', 'omni_clone'], true)) {
                    $limits[$variant['tool']['name']][$channel] = app(InputBoundary::class)->characterLimit($preview, $variant['action'], $channel);
                }
            }
        }

        return ['families' => array_map(fn ($names) => array_values(array_unique($names)), $families), 'limits' => $limits, 'api' => $api];
    }

    public function voiceSummary(Voice $voice): array
    {
        return $this->voiceSummaries(collect([$voice]))[$voice->id];
    }

    public function voiceSummaries(\Illuminate\Support\Collection $voices): array
    {
        AdminAccess::authorize('admin.read');
        $omniPlans = [];
        if ($voices->contains(fn ($voice) => data_get($voice->meta, 'engine') === 'xomni')) {
            foreach (ServicePlan::orderBy('sort_order')->get() as $plan) {
                $speakers = collect(app(OmniSpeakerCatalog::class)->forCustomer(app(AdminV2Catalog::class)->planCustomer($plan), app()->getLocale()))
                    ->pluck('speakers')->flatten(1);
                foreach ($speakers->pluck('code')->unique() as $code) {
                    $omniPlans[$code][] = $plan->name;
                }
            }
        }

        return $voices->mapWithKeys(function ($voice) use ($omniPlans) {
            $omni = data_get($voice->meta, 'engine') === 'xomni';
            $plans = $omni ? ($omniPlans[$voice->code] ?? []) : ($voice->is_active ? $voice->planAccesses->where('is_active', true)->pluck('servicePlan.name')->filter()->values()->all() : []);

            return [$voice->id => ['omni' => $omni, 'plans' => $plans,
                'preview' => filled(data_get($voice->meta, 'preview_audio', data_get($voice->meta, 'preview_audio_path')))]];
        })->all();
    }
}
