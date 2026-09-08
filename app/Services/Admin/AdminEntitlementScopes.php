<?php

namespace App\Services\Admin;

use App\Models\PlanEntitlement;
use App\Models\ServicePlan;
use App\Services\CustomerApi\V2\ApiCatalog;
use App\Support\Admin\AdminAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminEntitlementScopes
{
    public const META_KEY = 'admin_api_scopes';

    public function explicit(ServicePlan $plan): array
    {
        $ownership = (array) data_get($plan->meta, self::META_KEY, []);

        return array_values(array_unique([...($ownership['explicit'] ?? []),
            ...array_diff($plan->api_allowed_tools ?? [], $ownership['derived'] ?? [])]));
    }

    /** Caller holds the plan lock in the same transaction as its entitlement/config mutation. */
    public function synchronize(ServicePlan $plan, ?array $explicit = null): void
    {
        $explicit ??= $this->explicit($plan);
        $channels = PlanEntitlement::fallbackChannels('api', true);
        $derived = [];
        $catalog = app(ApiCatalog::class);
        $groups = $plan->planEntitlements()->with('toolAction')->whereIn('entitlement_channel', $channels)
            ->get()->groupBy('tool_action_id');
        foreach ($groups as $rows) {
            $effective = $rows->sortBy(fn ($row) => array_search($row->entitlement_channel, $channels, true))->first();
            $scope = $catalog->scopeForAction((string) $effective->toolAction?->full_code);
            if ($effective->allowed && $scope) {
                $derived[] = $scope;
            }
        }
        $derived = array_values(array_unique($derived));
        $meta = $plan->meta ?? [];
        $meta[self::META_KEY] = ['version' => 1, 'explicit' => array_values($explicit), 'derived' => $derived];
        $plan->forceFill(['meta' => $meta,
            'api_allowed_tools' => array_values(array_unique([...$explicit, ...$derived]))])->save();
    }

    public function mutate(?int $id, array $attributes = [], string $operation = 'save'): PlanEntitlement
    {
        AdminAccess::authorize('admin.pricing');

        return DB::transaction(function () use ($id, $attributes, $operation) {
            $oldPlanId = $id ? (int) PlanEntitlement::findOrFail($id)->service_plan_id : null;
            $planIds = array_values(array_unique(array_filter([$oldPlanId, $attributes['service_plan_id'] ?? null])));
            $plans = ServicePlan::whereKey($planIds)->orderBy('id')->lockForUpdate()->get();
            $row = $id ? PlanEntitlement::lockForUpdate()->findOrFail($id) : new PlanEntitlement;
            if ($id && (int) $row->service_plan_id !== $oldPlanId) {
                throw ValidationException::withMessages(['entitlementServicePlanId' => __('admin_p1.reload')]);
            }
            if ($operation === 'delete') {
                app(AdminCatalogDeletion::class)->delete($row);
            } else {
                $row->fill($operation === 'toggle' ? ['allowed' => ! $row->allowed] : $attributes);
                $row->save();
            }
            foreach ($plans as $plan) {
                $this->synchronize($plan);
            }

            return $row;
        });
    }
}
