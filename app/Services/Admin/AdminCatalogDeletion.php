<?php

namespace App\Services\Admin;

use App\Domain\Payments\Models\Payment;
use App\Models\AdminOperation;
use App\Models\ApiJob;
use App\Models\Coupon;
use App\Models\CreditOrder;
use App\Models\CreditProduct;
use App\Models\CustomerEntitlement;
use App\Models\CustomerFile;
use App\Models\CustomerVoice;
use App\Models\MlJob;
use App\Models\PaymentIntent;
use App\Models\PaymentMethod;
use App\Models\PlanEntitlement;
use App\Models\PlanVoiceAccess;
use App\Models\PricingRule;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Models\UsageEvent;
use App\Models\Voice;
use App\Support\Admin\AdminAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminCatalogDeletion
{
    public function delete(Model $target): void
    {
        AdminAccess::authorize($target instanceof PricingRule || $target instanceof PlanEntitlement || $target instanceof PlanVoiceAccess || $target instanceof ServicePlan
            ? 'admin.pricing' : 'admin.catalog');
        DB::transaction(function () use ($target) {
            $current = $target->newQuery()->lockForUpdate()->findOrFail($target->getKey());
            if ($this->hasDependencies($current)) {
                throw ValidationException::withMessages(['delete' => __('admin_p0.dependencies')]);
            }
            $current->delete();
        }, 3);
    }

    public function hasDependencies(Model $target): bool
    {
        if ($target instanceof Tool) {
            return $target->actions()->exists() || MlJob::where('tool_id', $target->id)->exists()
                || CustomerFile::where('tool_code', $target->code)->exists() || ApiJob::where('tool_code', $target->code)->exists();
        }
        if ($target instanceof ToolAction) {
            return $target->mlJobs()->exists() || $target->pricingRules()->exists() || $target->entitlements()->exists()
                || $target->customerPricingRules()->exists() || $target->usageEvents()->exists()
                || CustomerEntitlement::where('tool_action_id', $target->id)->exists()
                || ApiJob::where('tool_action', $target->full_code)->exists()
                || CustomerFile::where('tool_code', $target->tool_code)->exists();
        }
        if ($target instanceof ServicePlan) {
            return $target->subscriptions()->exists() || $target->previousSubscriptions()->exists()
                || $target->pricingRules()->exists() || $target->planEntitlements()->exists()
                || $target->voiceAccesses()->exists() || $target->monthlyGrants()->exists()
                || CreditOrder::where('service_plan_id', $target->id)->exists() || $this->purchases($target, 'service_plan');
        }
        if ($target instanceof StoragePlan) {
            return $target->subscriptions()->exists() || $this->purchases($target, 'storage_plan')
                || CreditOrder::where('source_type', 'storage_plan')->where('meta->storage_plan_code', $target->code)->exists();
        }
        if ($target instanceof CreditProduct) {
            return $target->orders()->exists() || $this->purchases($target, 'credit_product')
                || CreditOrder::where('source_type', 'credit_product')->where('meta->product_code', $target->code)->exists();
        }
        if ($target instanceof Coupon) {
            return $target->redemptions()->exists() || Payment::where('coupon_id', $target->id)->exists()
                || CreditOrder::where('coupon_id', $target->id)->exists()
                || PaymentIntent::where('meta->coupon_code', $target->code)->exists();
        }
        if ($target instanceof PaymentMethod) {
            return PaymentIntent::where('payment_method', $target->code)->exists()
                || CreditOrder::where('payment_method', $target->code)->exists()
                || Payment::where('provider', $target->driver)->exists()
                || \App\Models\CustomerPaymentMethod::where('provider', $target->driver)->exists();
        }
        if ($target instanceof Voice) {
            return $target->planAccesses()->exists() || CustomerVoice::where('voice_id', $target->id)->exists()
                || MlJob::where('input->voice_code', $target->code)->orWhere('input->speaker', $target->code)->exists();
        }
        if ($target instanceof PricingRule) {
            return UsageEvent::where('pricing_rule_id', $target->id)->exists();
        }
        if ($target instanceof PlanEntitlement) {
            return MlJob::where('tool_action_id', $target->tool_action_id)->exists()
                || ApiJob::where('tool_action', $target->toolAction?->full_code)->exists();
        }
        if ($target instanceof PlanVoiceAccess) {
            // Retain access for every current or historical subscription referencing this plan.
            return $target->servicePlan()->where(fn (Builder $plan) => $plan
                ->whereHas('subscriptions')->orWhereHas('previousSubscriptions'))->exists();
        }

        return true;
    }

    private function purchases(Model $target, string $purpose): bool
    {
        $requestKey = $target instanceof CreditProduct ? 'product_id' : 'plan_id';
        $action = $target instanceof CreditProduct ? 'grant.addon' : ($target instanceof StoragePlan ? 'grant.storage' : 'grant.plan');

        return Payment::where('purchasable_type', $target->getMorphClass())->where('purchasable_id', $target->id)->exists()
            || PaymentIntent::where('purpose_type', $purpose)->where('purpose_id', $target->id)->exists()
            || AdminOperation::where('action', $action)->where('requested->'.$requestKey, $target->id)->exists();
    }
}
