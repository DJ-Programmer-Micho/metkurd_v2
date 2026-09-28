<?php

namespace App\Support\Admin;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Models\CreditOrder;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use App\Models\ServicePlanAgreement;
use App\Models\SubscriptionCreditAllocation;
use App\Services\Admin\AdminOperations;
use App\Services\Billing\BillingReportingBoundary;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/** Read-only presentation of persisted financial evidence; never a billing authority. */
final class AdminBillingWorkspace
{
    public const SECTIONS = ['payments', 'subscriptions', 'storage_subscriptions', 'orders'];

    public const STATES = ['needs_review', 'processing', 'failed', 'resolved', 'unknown'];

    public const CASES = ['cancellation', 'provider_mismatch', 'application', 'verification', 'payment_review'];

    // Shared by filters and row labels, including the existing dashboard review queue.
    public static function stateSql(): string
    {
        $closed = \Illuminate\Support\Facades\DB::connection()->getQueryGrammar()->wrap('meta->review_resolution->closed_at');
        $closedNull = in_array(\Illuminate\Support\Facades\DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? "($closed IS NULL OR JSON_TYPE(JSON_EXTRACT(meta, '$.review_resolution.closed_at')) = 'NULL')"
            : "$closed IS NULL";

        return "CASE WHEN JSON_EXTRACT(meta, '$.provider_cancellation.provider_cancel_pending') = true
            OR (internal_status = 'requires_review' AND ($closedNull OR $closed = '')) THEN 'needs_review'
            WHEN (NOT ($closedNull) AND $closed != '') OR internal_status IN ('applied', 'refunded') THEN 'resolved'
            WHEN internal_status IN ('failed', 'canceled', 'expired') THEN 'failed'
            WHEN internal_status IN ('pending', 'awaiting_customer_action', 'paid_pending_application') THEN 'processing'
            ELSE 'unknown' END";
    }

    public static function caseSql(): string
    {
        return "CASE WHEN JSON_EXTRACT(meta, '$.provider_cancellation.provider_cancel_pending') = true THEN 'cancellation'
            WHEN internal_status = 'requires_review' AND mismatch_reason IS NOT NULL AND mismatch_reason != '' THEN 'provider_mismatch'
            WHEN internal_status = 'paid_pending_application' THEN 'application'
            WHEN internal_status IN ('pending', 'awaiting_customer_action') THEN 'verification'
            WHEN internal_status = 'requires_review' THEN 'payment_review' ELSE 'unknown' END";
    }

    public function rows(Collection $models): \Illuminate\Support\Collection
    {
        AdminAccess::authorize('admin.read');
        $boundary = app(BillingReportingBoundary::class)->current();
        $reader = app(AdminOperations::class);
        // Storage subscriptions have no customer relation; batch the names without changing the model.
        $storageCustomerIds = $models->filter(fn ($m) => $m instanceof CustomerStorageSubscription)->pluck('customer_id')->unique();
        $storageCustomers = $storageCustomerIds->isEmpty() ? collect() : \App\Models\Customer::whereIn('id', $storageCustomerIds)->pluck('username', 'id');
        $effective = [];
        foreach ([CustomerServiceSubscription::class, CustomerStorageSubscription::class] as $type) {
            $customers = $models->filter(fn ($m) => $m instanceof $type)->pluck('customer_id')->unique();
            $effective[$type] = $customers->isEmpty() ? [] : $type::query()->effectiveAt()->whereIn('customer_id', $customers)
                ->selectRaw('MAX(id) as id')->groupBy('customer_id')->pluck('id')->all();
        }
        $subscriptionIds = $models->filter(fn ($m) => $m instanceof CustomerServiceSubscription)->pluck('id');
        $agreements = $subscriptionIds->isEmpty() ? collect() : ServicePlanAgreement::whereIn('subscription_id', $subscriptionIds)
            ->get(['id', 'customer_id', 'subscription_id', 'reference', 'status', 'starts_at', 'ends_at'])->keyBy('subscription_id');

        return $models->map(function ($model) use ($reader, $boundary, $effective, $agreements, $storageCustomers) {
            $row = $reader->row($model);
            $row['customer'] = AdminData::redact($model instanceof CustomerStorageSubscription ? $storageCustomers->get($model->customer_id) : $model->customer?->username);
            if ($model instanceof Payment || $model instanceof CreditOrder) {
                $row['financial_era'] = self::era($model, $boundary);
            }
            if ($model instanceof Payment) {
                $row['billing_state'] = $model->billing_state ?? 'unknown';
                $row['billing_case'] = $model->billing_case ?? 'unknown';
                $row['updated_at'] = $model->updated_at?->format('Y-m-d H:i:s');
            } elseif ($model instanceof CustomerServiceSubscription || $model instanceof CustomerStorageSubscription) {
                $row['effective_now'] = in_array($model->id, $effective[$model::class], true);
                $row['source_label'] = AdminCustomerWorkspace::sourceLabel($model->source);
                $agreement = $agreements->get($model->id);
                if ($model instanceof CustomerServiceSubscription && $agreement && (int) $agreement->customer_id === (int) $model->customer_id) {
                    $row['agreement'] = ['id' => $agreement->id, 'reference' => AdminData::redact($agreement->reference), 'status' => $agreement->status,
                        'starts_at' => $agreement->starts_at?->format('Y-m-d'), 'ends_at' => $agreement->ends_at?->copy()->subDay()->format('Y-m-d')];
                }
            } elseif ($model instanceof CreditOrder) {
                // Keep recorded currencies attached to their own amounts; no conversion or revenue inference.
                $row['base_amount_iqd'] = $model->base_amount_iqd;
                $row['amount_usd'] = $model->amount_usd;
            }
            if (! $model instanceof Payment && $model->payment && (int) $model->payment->customer_id === (int) $model->customer_id) {
                $row['payment_era'] = self::era($model->payment, $boundary);
            } elseif (! $model instanceof Payment) {
                $row['payment_id'] = null;
            }

            return $row;
        });
    }

    private static function era(Payment|CreditOrder $model, ?array $boundary): string
    {
        $watermark = $model instanceof Payment ? 'payment_id' : 'credit_order_id';

        return $boundary && (! $model->created_at || $model->created_at->lt($boundary['starts_at']) || $model->id <= $boundary[$watermark]) ? 'legacy' : 'current';
    }

    public function evidence(Payment|CustomerServiceSubscription|CustomerStorageSubscription $model): array
    {
        $admin = AdminAccess::authorize('admin.read');
        if (! Gate::forUser($admin)->allows('admin.finance') && ! Gate::forUser($admin)->allows('admin.reconcile')) {
            return ['restricted' => true];
        }
        $events = null;
        $allocations = null;
        if ($model instanceof Payment) {
            $events = PaymentEvent::where('payment_id', $model->id)->latest('id')
                ->paginate(10, ['id', 'payment_id', 'provider', 'event_type', 'source', 'before_status', 'after_status', 'response_code', 'created_at', 'processed_at'], 'eventsPage');
            $allocations = SubscriptionCreditAllocation::where('customer_id', $model->customer_id)->where('payment_id', $model->id);
        } elseif ($model instanceof CustomerServiceSubscription) {
            $allocations = SubscriptionCreditAllocation::where('customer_id', $model->customer_id)->where('subscription_id', $model->id);
        }
        $allocations = $allocations?->latest('id')->paginate(10, ['id', 'subscription_id', 'payment_id', 'allocation_type', 'cycle_started_at', 'paid_through', 'status', 'applied_at'], 'allocationsPage');
        foreach ([$events, $allocations] as $page) {
            $page?->through(fn ($row) => AdminData::redact($row->toArray()));
        }

        return ['restricted' => false, 'customer_id' => $model->customer_id, 'events' => $events, 'allocations' => $allocations];
    }
}
