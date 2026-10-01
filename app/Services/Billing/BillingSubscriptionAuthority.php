<?php

namespace App\Services\Billing;

use App\Domain\Payments\Support\FibSubscriptionTimestamp;
use App\Models\CustomerServiceSubscription;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/** Read-only eligibility; retained balances and historical rows never confer paid access. */
class BillingSubscriptionAuthority
{
    public const LOCAL_SOURCES = ['admin_manual', 'admin_manual_grant', 'internal_non_revenue', 'admin', 'manual'];

    public function apply(Builder $query, ?CarbonInterface $at = null, bool $lifecycle = false, ?array $boundary = null): Builder
    {
        $boundary ??= app(BillingReportingBoundary::class)->current();
        if (! $boundary) {
            return $query;
        }
        $at ??= now();
        $model = $query->getModel();
        $table = $model->getTable();
        if (($boundary['mode'] ?? null) === PaymentDomainCutover::FULL_LOCAL_RESET) {
            $query->where($table.'.id', '>', $boundary[$table]);
        }
        $service = $model instanceof CustomerServiceSubscription;
        $planKey = $service ? 'service_plan_id' : 'storage_plan_id';
        $planClass = $service ? ServicePlan::class : StoragePlan::class;
        // Parse historical offsets in PHP, not database-specific JSON date coercion.
        // This query deliberately has no effectiveAt scope (and cannot recurse).
        $localIds = [];
        foreach ($model->newQuery()->whereNull('payment_id')->whereIn('source', self::LOCAL_SOURCES)->cursor() as $row) {
            $checkAt = $lifecycle ? CarbonImmutable::parse($boundary['starts_at'])->max($row->created_at ?? $boundary['starts_at']) : $at;
            $end = $row->ends_at;
            foreach (['period_ends_at', 'provider_active_until'] as $key) {
                $raw = data_get($row->meta, $key);
                if ($raw !== null) {
                    $parsed = FibSubscriptionTimestamp::parse($raw);
                    if (! $parsed) {
                        continue 2; // Ambiguous terms are not indefinite grants.
                    }
                    $end = $end ? $end->min($parsed) : $parsed;
                }
            }
            $end ??= $row->cycle_ends_on?->copy()->endOfDay();
            if ($end && $end->gt($checkAt)) {
                $localIds[] = $row->id;
            }
        }
        $onlineIds = [];
        if (! $lifecycle) {
            foreach ($model->newQuery()->with('payment')->whereHas('payment', fn ($p) => $p->currentBillingPeriod()->where('status', 'paid')
                ->where(fn ($kind) => $kind->whereNull('provider_object_type')->orWhere('provider_object_type', '!=', 'subscription')))->get() as $row) {
                if (app(SubscriptionCyclePolicy::class)->boundary($row)?->gt($at)) {
                    $onlineIds[] = $row->id;
                }
            }
        }

        $retainedIds = ($lifecycle || ($boundary['mode'] ?? null) === PaymentDomainCutover::FULL_LOCAL_RESET) ? [] : app(ProviderCoverageDispositions::class)->eligibleIds($model::class, $at);

        return $query->where(function (Builder $eligible) use ($table, $planKey, $planClass, $service, $localIds, $onlineIds, $retainedIds, $at, $lifecycle) {
            $eligible->whereIn($table.'.id', $localIds)
                ->orWhereIn($table.'.id', $retainedIds)
                ->orWhere(function (Builder $free) use ($table, $service) {
                    $free->whereNull($table.'.payment_id')
                        ->where(fn ($q) => $q->whereNull($table.'.source')->orWhereIn($table.'.source', ['system', 'free']))
                        ->whereHas($service ? 'servicePlan' : 'storagePlan', fn ($q) => $service ? $q->where('is_free', true) : $q->where('price_iqd', 0));
                })
                ->orWhereHas('payment', fn ($paid) => $paid->currentBillingPeriod()->where('status', 'paid')
                    ->when(! $lifecycle, fn ($p) => $p->where(fn ($term) => $term->where('payments.active_until', '>', $at)->orWhereIn($table.'.id', $onlineIds)))
                    ->whereNotNull('paid_at')->whereNull('review_required_at')
                    ->where('purchasable_type', $planClass)
                    ->whereColumn('payments.customer_id', $table.'.customer_id')
                    ->whereColumn('payments.purchasable_id', $table.'.'.$planKey));
            if ($service) {
                $eligible->orWhere(fn ($cash) => $cash->where($table.'.source', ServiceAgreementLifecycle::SOURCE)
                    ->whereNull($table.'.payment_id')->whereExists(function ($agreement) use ($table, $at, $lifecycle) {
                        $agreement->selectRaw('1')->from('service_plan_agreements')
                            ->whereColumn('subscription_id', $table.'.id')->whereColumn('customer_id', $table.'.customer_id')
                            ->whereColumn('service_plan_id', $table.'.service_plan_id')->where('status', 'active')
                            ->where('starts_at', '<=', $at);
                        if (! $lifecycle) {
                            $agreement->where('ends_at', '>', $at);
                        }
                    }));
            }
        });
    }
}
