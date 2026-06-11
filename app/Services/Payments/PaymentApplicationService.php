<?php

namespace App\Services\Payments;

use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use App\Services\Billing\CustomerBillingStateService;
use Illuminate\Support\Facades\Log;

class PaymentApplicationService
{
    public function __construct(
        protected CustomerBillingStateService $billingState,
        protected PaymentEventRecorder $events,
    ) {}

    /**
     * @return array{can_apply:bool,reason:?string,context:array<string,mixed>}
     */
    public function assess(Payment $payment): array
    {
        return match ($payment->purchase_type) {
            PurchaseType::PLAN_SUBSCRIPTION => $this->assessPlanSubscription($payment),
            PurchaseType::STORAGE_SUBSCRIPTION => $this->assessStorageSubscription($payment),
            default => [
                'can_apply' => true,
                'reason' => null,
                'context' => [],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function markRequiresReview(Payment $payment, string $reason, array $context = []): void
    {
        $meta = array_merge((array) $payment->meta, [
            'application_review' => array_filter([
                'reason' => $reason,
                'context' => $context,
                'recorded_at' => now()->toIso8601String(),
            ], static fn (mixed $value): bool => $value !== null),
        ]);

        $payment->forceFill([
            'internal_status' => PaymentInternalStatus::REQUIRES_REVIEW,
            'review_required_at' => $payment->review_required_at ?? now(),
            'mismatch_reason' => $reason,
            'meta' => $meta,
        ])->save();

        $this->events->record($payment, [
            'event_type' => 'payment_requires_review',
            'source' => 'payment_application_guard',
            'event_key' => 'payment-review:'.$payment->id,
            'before_status' => $payment->status->value,
            'after_status' => $payment->status->value,
            'meta' => [
                'internal_status' => PaymentInternalStatus::REQUIRES_REVIEW->value,
                'reason' => $reason,
                'context' => $context,
            ],
        ]);

        Log::warning('Payment marked for manual review due to subscription mismatch.', [
            'payment_id' => (int) $payment->id,
            'payment_uuid' => (string) $payment->uuid,
            'customer_id' => (int) $payment->customer_id,
            'purchase_type' => (string) ($payment->purchase_type?->value ?? ''),
            'provider_reference' => $payment->providerReference(),
            'reason' => $reason,
            'context' => $context,
        ]);
    }

    /**
     * @return array{can_apply:bool,reason:?string,context:array<string,mixed>}
     */
    protected function assessPlanSubscription(Payment $payment): array
    {
        $snapshot = $payment->snapshot();
        $customer = $payment->customer;
        $defaultPlan = $this->billingState->defaultServicePlan();
        $defaultPlanId = (int) $defaultPlan->id;
        $expectedCurrentPlanId = (int) data_get($snapshot, 'checkout_context.current_service_plan_id', 0);
        $expectedCurrentPlanCode = (string) data_get($snapshot, 'checkout_context.current_service_plan_code', '');
        $intendedPlanId = (int) ($payment->purchasable_id ?: data_get($snapshot, 'intended_plan.id', 0));
        $intendedPlanCode = (string) data_get($snapshot, 'code', data_get($snapshot, 'intended_plan.code', ''));
        $intendedPlanName = (string) data_get($snapshot, 'name', data_get($snapshot, 'intended_plan.name', 'Subscription'));

        $currentSubscription = CustomerServiceSubscription::query()
            ->with('servicePlan:id,code,name,is_free')
            ->lockForUpdate()
            ->where('customer_id', $payment->customer_id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->latest('id')
            ->first();

        $currentPlan = $currentSubscription?->servicePlan ?? $customer?->currentServicePlan();
        $currentPlanId = (int) ($currentPlan?->id ?? $defaultPlanId);
        $currentPlanCode = (string) ($currentPlan?->code ?? $defaultPlan->code ?? '');
        $currentPlanName = (string) ($currentPlan?->name ?? $defaultPlan->name ?? 'Free');
        $currentIsDefault = $currentPlanId === $defaultPlanId || (bool) ($currentPlan?->is_free ?? false);

        if ($currentPlanId === $intendedPlanId || $currentIsDefault) {
            return [
                'can_apply' => true,
                'reason' => null,
                'context' => [
                    'expected_current_plan_id' => $expectedCurrentPlanId ?: null,
                    'current_plan_id' => $currentPlanId,
                    'intended_plan_id' => $intendedPlanId,
                ],
            ];
        }

        if ($expectedCurrentPlanId > 0 && $currentPlanId === $expectedCurrentPlanId) {
            return [
                'can_apply' => true,
                'reason' => null,
                'context' => [
                    'expected_current_plan_id' => $expectedCurrentPlanId,
                    'current_plan_id' => $currentPlanId,
                    'intended_plan_id' => $intendedPlanId,
                ],
            ];
        }

        $reason = sprintf(
            'Customer currently has %s but this FIB payment was created for %s.',
            $currentPlanName,
            $intendedPlanName
        );

        return [
            'can_apply' => false,
            'reason' => $reason,
            'context' => [
                'current_plan_id' => $currentPlanId,
                'current_plan_code' => $currentPlanCode,
                'current_plan_name' => $currentPlanName,
                'expected_current_plan_id' => $expectedCurrentPlanId ?: null,
                'expected_current_plan_code' => $expectedCurrentPlanCode !== '' ? $expectedCurrentPlanCode : null,
                'intended_plan_id' => $intendedPlanId ?: null,
                'intended_plan_code' => $intendedPlanCode !== '' ? $intendedPlanCode : null,
                'intended_plan_name' => $intendedPlanName,
            ],
        ];
    }

    /**
     * @return array{can_apply:bool,reason:?string,context:array<string,mixed>}
     */
    protected function assessStorageSubscription(Payment $payment): array
    {
        $snapshot = $payment->snapshot();
        $customer = $payment->customer;
        $defaultPlan = $this->billingState->defaultStoragePlan();
        $defaultPlanId = (int) $defaultPlan->id;
        $expectedCurrentPlanId = (int) data_get($snapshot, 'checkout_context.current_storage_plan_id', 0);
        $expectedCurrentPlanCode = (string) data_get($snapshot, 'checkout_context.current_storage_plan_code', '');
        $intendedPlanId = (int) ($payment->purchasable_id ?: data_get($snapshot, 'intended_plan.id', 0));
        $intendedPlanCode = (string) data_get($snapshot, 'code', data_get($snapshot, 'intended_plan.code', ''));
        $intendedPlanName = (string) data_get($snapshot, 'name', data_get($snapshot, 'intended_plan.name', 'Storage'));

        $currentSubscription = CustomerStorageSubscription::query()
            ->with('storagePlan:id,code,name')
            ->lockForUpdate()
            ->where('customer_id', $payment->customer_id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->latest('id')
            ->first();

        $currentPlan = $currentSubscription?->storagePlan ?? $customer?->currentStoragePlan();
        $currentPlanId = (int) ($currentPlan?->id ?? $defaultPlanId);
        $currentPlanCode = (string) ($currentPlan?->code ?? $defaultPlan->code ?? '');
        $currentPlanName = (string) ($currentPlan?->name ?? $defaultPlan->name ?? 'Free');
        $currentIsDefault = $currentPlanId === $defaultPlanId;

        if ($currentPlanId === $intendedPlanId || $currentIsDefault) {
            return [
                'can_apply' => true,
                'reason' => null,
                'context' => [
                    'expected_current_storage_plan_id' => $expectedCurrentPlanId ?: null,
                    'current_storage_plan_id' => $currentPlanId,
                    'intended_storage_plan_id' => $intendedPlanId,
                ],
            ];
        }

        if ($expectedCurrentPlanId > 0 && $currentPlanId === $expectedCurrentPlanId) {
            return [
                'can_apply' => true,
                'reason' => null,
                'context' => [
                    'expected_current_storage_plan_id' => $expectedCurrentPlanId,
                    'current_storage_plan_id' => $currentPlanId,
                    'intended_storage_plan_id' => $intendedPlanId,
                ],
            ];
        }

        $reason = sprintf(
            'Customer currently has storage plan %s but this FIB payment was created for %s.',
            $currentPlanName,
            $intendedPlanName
        );

        return [
            'can_apply' => false,
            'reason' => $reason,
            'context' => [
                'current_storage_plan_id' => $currentPlanId,
                'current_storage_plan_code' => $currentPlanCode,
                'current_storage_plan_name' => $currentPlanName,
                'expected_current_storage_plan_id' => $expectedCurrentPlanId ?: null,
                'expected_current_storage_plan_code' => $expectedCurrentPlanCode !== '' ? $expectedCurrentPlanCode : null,
                'intended_storage_plan_id' => $intendedPlanId ?: null,
                'intended_storage_plan_code' => $intendedPlanCode !== '' ? $intendedPlanCode : null,
                'intended_storage_plan_name' => $intendedPlanName,
            ],
        ];
    }
}
