<?php

namespace App\Services\Billing;

use App\Domain\Payments\Models\Payment;
use App\Models\CreditLedger;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\ServicePlan;
use App\Models\SubscriptionCreditAllocation;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class CreditService
{
    public function charge(
        int $customerId,
        int $credits,
        string $type,
        array $meta = [],
        string $walletType = CreditWallet::TYPE_APP,
    ): bool {
        if ($credits <= 0) {
            return false;
        }

        return DB::transaction(function () use ($customerId, $credits, $type, $meta, $walletType) {
            $wallet = $this->lockWallet($customerId, $walletType);
            $referenceCode = trim((string) ($meta['reference_code'] ?? ''));

            // The wallet row serializes same-customer requests. This check is
            // deliberately inside that lock because a logical charge may have
            // two ledger rows when subscription and add-on credits are split.
            if ($referenceCode !== '' && CreditLedger::query()
                ->where('customer_id', $customerId)
                ->where('wallet_type', $walletType)
                ->where('direction', 'debit')
                ->where('reference_code', $referenceCode)
                ->exists()) {
                return false;
            }
            $subscription = (int) ($wallet->subscription_balance_credits ?? 0);
            $addon = (int) ($wallet->addon_balance_credits ?? 0);
            $bucketCombined = $subscription + $addon;
            $storedCombined = (int) ($wallet->balance_credits ?? 0);

            if ($bucketCombined <= 0 && $storedCombined > 0) {
                $addon = $storedCombined;
                $wallet->subscription_balance_credits = $subscription;
                $wallet->addon_balance_credits = $addon;
                $wallet->syncCombinedBalance();
                $wallet->save();
                $bucketCombined = (int) $wallet->balance_credits;
            }

            if ($bucketCombined < $credits) {
                throw new \RuntimeException('Not enough credits.');
            }

            $remaining = $credits;
            $fromSubscription = min($subscription, $remaining);
            $subscription -= $fromSubscription;
            $remaining -= $fromSubscription;

            $fromAddon = min($addon, $remaining);
            $addon -= $fromAddon;
            $remaining -= $fromAddon;

            if ($remaining > 0) {
                throw new \RuntimeException('Not enough credits.');
            }

            $balanceBefore = (int) ($wallet->balance_credits ?? 0);
            $wallet->subscription_balance_credits = $subscription;
            $wallet->addon_balance_credits = $addon;
            $wallet->syncCombinedBalance();
            $wallet->lifetime_spent = (int) ($wallet->lifetime_spent ?? 0) + $credits;
            $wallet->last_charged_at = now();
            $wallet->save();

            $referenceCode = $referenceCode !== '' ? $referenceCode : $this->makeReferenceCode($type);

            if ($fromSubscription > 0) {
                $this->writeLedger(
                    customerId: $customerId,
                    walletType: $walletType,
                    type: $type,
                    direction: 'debit',
                    sourceType: (string) ($meta['source_type'] ?? 'web_tool'),
                    bucket: 'subscription',
                    creditsDelta: -$fromSubscription,
                    amount: $fromSubscription,
                    balanceBefore: $balanceBefore,
                    wallet: $wallet,
                    referenceCode: $referenceCode,
                    meta: array_merge($meta, [
                        'bucket_spent' => 'subscription',
                        'charged_total' => $credits,
                        'charged_part' => $fromSubscription,
                    ]),
                );
            }

            if ($fromAddon > 0) {
                $this->writeLedger(
                    customerId: $customerId,
                    walletType: $walletType,
                    type: $type,
                    direction: 'debit',
                    sourceType: (string) ($meta['source_type'] ?? 'web_tool'),
                    bucket: 'addon',
                    creditsDelta: -$fromAddon,
                    amount: $fromAddon,
                    balanceBefore: $balanceBefore,
                    wallet: $wallet,
                    referenceCode: $referenceCode,
                    meta: array_merge($meta, [
                        'bucket_spent' => 'addon',
                        'charged_total' => $credits,
                        'charged_part' => $fromAddon,
                    ]),
                );
            }

            return true;
        }, 3);
    }

    public function refund(
        int $customerId,
        int $credits,
        string $type,
        array $meta = [],
        string $walletType = CreditWallet::TYPE_APP,
    ): bool {
        if ($credits <= 0) {
            return false;
        }

        return DB::transaction(function () use ($customerId, $credits, $type, $meta, $walletType) {
            $wallet = $this->lockWallet($customerId, $walletType);
            $referenceCode = trim((string) ($meta['reference_code'] ?? ''));

            if ($referenceCode !== '' && CreditLedger::query()
                ->where('customer_id', $customerId)
                ->where('wallet_type', $walletType)
                ->where('direction', 'refund')
                ->where('reference_code', $referenceCode)
                ->exists()) {
                return false;
            }
            $refundBucket = $meta['refund_bucket'] ?? $meta['bucket'] ?? 'addon';
            $subscription = (int) ($wallet->subscription_balance_credits ?? 0);
            $addon = (int) ($wallet->addon_balance_credits ?? 0);
            $balanceBefore = (int) ($wallet->balance_credits ?? 0);

            if ($refundBucket === 'subscription') {
                $subscription += $credits;
            } else {
                $refundBucket = 'addon';
                $addon += $credits;
            }

            $wallet->subscription_balance_credits = $subscription;
            $wallet->addon_balance_credits = $addon;
            $wallet->syncCombinedBalance();
            $wallet->lifetime_refunded = (int) ($wallet->lifetime_refunded ?? 0) + $credits;
            $wallet->save();

            $this->writeLedger(
                customerId: $customerId,
                walletType: $walletType,
                type: $type,
                direction: 'refund',
                sourceType: (string) ($meta['source_type'] ?? 'refund'),
                bucket: $refundBucket,
                creditsDelta: $credits,
                amount: $credits,
                balanceBefore: $balanceBefore,
                wallet: $wallet,
                referenceCode: $referenceCode !== '' ? $referenceCode : $this->makeReferenceCode($type),
                meta: $meta,
            );

            return true;
        }, 3);
    }

    /**
     * Restore a complete App MlJob charge allocation; never infer a bucket from the current wallet.
     * Returns false only when an identical complete refund already exists.
     * Inconsistent evidence throws before any mutation; callers must retain review/retry state.
     */
    public function refundFromCharge(
        int $customerId,
        int $credits,
        string $chargeReference,
        string $type,
        array $meta,
    ): bool {
        return DB::transaction(function () use ($customerId, $credits, $chargeReference, $type, $meta): bool {
            $reference = trim((string) ($meta['reference_code'] ?? ''));
            $jobId = (string) ($meta['ml_job_id'] ?? '');
            if ($credits <= 0 || trim($chargeReference) === '' || $reference === '' || $jobId === '' || $reference === $chargeReference) {
                throw new \DomainException('invalid_refund_identity');
            }

            // Use the same serialization boundary as charge()/refund(), but never
            // create a missing wallet while investigating inconsistent evidence.
            $wallet = CreditWallet::query()->where('customer_id', $customerId)
                ->where('wallet_type', CreditWallet::TYPE_APP)->lockForUpdate()->first();
            if (! $wallet) {
                throw new \DomainException('missing_app_wallet');
            }

            $owned = CreditLedger::query()->where('customer_id', $customerId)
                ->where('wallet_type', CreditWallet::TYPE_APP);
            // References are not globally unique. Only explicit links to this job
            // can establish an ownership conflict outside the owned wallet.
            $conflictingIdentity = CreditLedger::query()->whereIn('reference_code', [$chargeReference, $reference])
                ->where(fn ($query) => $query->where('ml_job_id', $jobId)
                    ->orWhere(fn ($related) => $related->where('related_type', 'ml_job')->where('related_id', $jobId)))
                ->where(fn ($query) => $query->where('customer_id', '!=', $customerId)->orWhere('wallet_type', '!=', CreditWallet::TYPE_APP))
                ->exists();
            if ($conflictingIdentity) {
                throw new \DomainException('conflicting_ledger_identity');
            }

            $allocation = function ($rows, string $direction) use ($jobId, $type): array {
                $parts = ['subscription' => 0, 'addon' => 0];
                foreach ($rows as $row) {
                    if (! array_key_exists((string) $row->bucket, $parts) || (int) $row->amount <= 0
                        || (int) $row->credits_delta !== ($direction === 'debit' ? -1 : 1) * (int) $row->amount
                        || ($row->ml_job_id && (string) $row->ml_job_id !== $jobId)
                        || ($row->related_type === 'ml_job' && (string) $row->related_id !== $jobId)
                        || $row->api_job_id
                        || ($direction === 'refund' && ($row->direction !== 'refund' || $row->type !== $type))) {
                        throw new \DomainException('invalid_'.$direction.'_evidence');
                    }
                    $parts[$row->bucket] += (int) $row->amount;
                }

                return $parts;
            };
            $debits = (clone $owned)->where('direction', 'debit')->where('reference_code', $chargeReference)->lockForUpdate()->get();
            $expected = $allocation($debits, 'debit');
            if (array_sum($expected) !== $credits) {
                throw new \DomainException('debit_total_mismatch');
            }
            $refunds = (clone $owned)->where('reference_code', $reference)->lockForUpdate()->get();
            if ($refunds->isNotEmpty()) {
                if ($allocation($refunds, 'refund') !== $expected) {
                    throw new \DomainException('refund_allocation_mismatch');
                }

                return false;
            }

            $balanceBefore = (int) $wallet->balance_credits;
            $wallet->subscription_balance_credits += $expected['subscription'];
            $wallet->addon_balance_credits += $expected['addon'];
            $wallet->syncCombinedBalance();
            $wallet->lifetime_refunded = (int) $wallet->lifetime_refunded + $credits;
            $wallet->save();
            foreach ($expected as $bucket => $amount) {
                if ($amount === 0) {
                    continue;
                }
                $this->writeLedger(
                    customerId: $customerId,
                    walletType: CreditWallet::TYPE_APP,
                    type: $type,
                    direction: 'refund',
                    sourceType: 'refund',
                    bucket: $bucket,
                    creditsDelta: $amount,
                    amount: $amount,
                    balanceBefore: $balanceBefore,
                    wallet: $wallet,
                    referenceCode: $reference,
                    meta: array_merge($meta, ['refund_bucket' => $bucket, 'refunded_total' => $credits, 'refunded_part' => $amount]),
                );
            }

            return true;
        }, 3);
    }

    public function grantMonthlyCredits(
        int $customerId,
        int $credits,
        array $meta = [],
        string $walletType = CreditWallet::TYPE_APP,
    ): void {
        if ($credits <= 0) {
            return;
        }

        DB::transaction(function () use ($customerId, $credits, $meta, $walletType) {
            $wallet = $this->lockWallet($customerId, $walletType);
            $balanceBefore = (int) ($wallet->balance_credits ?? 0);
            $subscription = (int) ($wallet->subscription_balance_credits ?? 0) + $credits;
            $addon = (int) ($wallet->addon_balance_credits ?? 0);

            $wallet->subscription_balance_credits = $subscription;
            $wallet->addon_balance_credits = $addon;
            $wallet->syncCombinedBalance();
            $wallet->lifetime_earned = (int) ($wallet->lifetime_earned ?? 0) + $credits;
            $wallet->last_granted_at = now();
            $wallet->save();

            $this->writeLedger(
                customerId: $customerId,
                walletType: $walletType,
                type: 'monthly_grant',
                direction: 'credit',
                sourceType: (string) ($meta['source_type'] ?? 'subscription_refill'),
                bucket: 'subscription',
                creditsDelta: $credits,
                amount: $credits,
                balanceBefore: $balanceBefore,
                wallet: $wallet,
                referenceCode: (string) ($meta['reference_code'] ?? $this->makeReferenceCode('monthly_grant')),
                meta: $meta,
            );
        }, 3);
    }

    public function addAddonCredits(
        int $customerId,
        int $credits,
        array $meta = [],
        string $walletType = CreditWallet::TYPE_APP,
    ): void {
        if ($credits <= 0) {
            return;
        }

        DB::transaction(function () use ($customerId, $credits, $meta, $walletType) {
            $wallet = $this->lockWallet($customerId, $walletType);
            $balanceBefore = (int) ($wallet->balance_credits ?? 0);
            $subscription = (int) ($wallet->subscription_balance_credits ?? 0);
            $addon = (int) ($wallet->addon_balance_credits ?? 0) + $credits;

            $wallet->subscription_balance_credits = $subscription;
            $wallet->addon_balance_credits = $addon;
            $wallet->syncCombinedBalance();
            $wallet->lifetime_earned = (int) ($wallet->lifetime_earned ?? 0) + $credits;
            $wallet->save();

            $this->writeLedger(
                customerId: $customerId,
                walletType: $walletType,
                type: 'addon_purchase',
                direction: 'credit',
                sourceType: (string) ($meta['source_type'] ?? 'addon'),
                bucket: 'addon',
                creditsDelta: $credits,
                amount: $credits,
                balanceBefore: $balanceBefore,
                wallet: $wallet,
                referenceCode: (string) ($meta['reference_code'] ?? $this->makeReferenceCode('addon_purchase')),
                meta: $meta,
            );
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function syncCustomerSubscriptionCreditsToPlan(Customer|int $customer, ServicePlan $plan, array $meta = []): array
    {
        $customerId = $customer instanceof Customer ? (int) $customer->id : (int) $customer;
        $currentAllowances = [
            CreditWallet::TYPE_APP => max(0, (int) data_get($meta, 'current_allowances.app', $plan->appMonthlyCredits())),
            CreditWallet::TYPE_API => max(0, (int) data_get($meta, 'current_allowances.api', $plan->apiMonthlyCredits())),
        ];
        $cycleStartedOn = $this->normalizeDate(data_get($meta, 'cycle_started_on'));
        $cycleEndsOn = $this->normalizeDate(data_get($meta, 'cycle_ends_on'));
        $currentCycleKey = trim((string) data_get($meta, 'current_cycle_key', ''));
        $type = (string) ($meta['type'] ?? 'admin_credit_sync');
        $referenceCode = (string) ($meta['reference_code'] ?? $this->makeReferenceCode($type));

        return DB::transaction(function () use (
            $customerId,
            $plan,
            $meta,
            $currentAllowances,
            $cycleStartedOn,
            $cycleEndsOn,
            $currentCycleKey,
            $type,
            $referenceCode
        ) {
            $appResult = $this->syncWalletSubscriptionAllowance(
                customerId: $customerId,
                walletType: CreditWallet::TYPE_APP,
                targetAllowance: $plan->appMonthlyCredits(),
                currentAllowance: $currentAllowances[CreditWallet::TYPE_APP],
                type: $type,
                referenceCode: $referenceCode,
                meta: $meta,
                plan: $plan,
                cycleStartedOn: $cycleStartedOn,
                cycleEndsOn: $cycleEndsOn,
                currentCycleKey: $currentCycleKey,
            );

            $apiResult = $this->syncWalletSubscriptionAllowance(
                customerId: $customerId,
                walletType: CreditWallet::TYPE_API,
                targetAllowance: $plan->apiMonthlyCredits(),
                currentAllowance: $currentAllowances[CreditWallet::TYPE_API],
                type: $type,
                referenceCode: $referenceCode,
                meta: $meta,
                plan: $plan,
                cycleStartedOn: $cycleStartedOn,
                cycleEndsOn: $cycleEndsOn,
                currentCycleKey: $currentCycleKey,
            );

            return [
                'customer_id' => $customerId,
                'plan_id' => (int) $plan->id,
                'plan_code' => (string) $plan->code,
                'app' => $appResult,
                'api' => $apiResult,
                'app_added_credits' => (int) $appResult['added_credits'],
                'api_added_credits' => (int) $apiResult['added_credits'],
                'changed' => (bool) $appResult['changed'] || (bool) $apiResult['changed'],
                'reference_code' => $referenceCode,
            ];
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{applied:bool,already_applied:bool,app_delta:int,api_delta:int,provider_cycle_key:string}
     */
    public function applyProviderRenewalCycle(CustomerServiceSubscription|int $subscription, array $meta = []): array
    {
        $subscriptionId = $subscription instanceof CustomerServiceSubscription ? (int) $subscription->id : (int) $subscription;
        $providerCycleKey = trim((string) data_get($meta, 'provider_cycle_key', ''));

        if ($subscriptionId <= 0 || $providerCycleKey === '') {
            return [
                'applied' => false,
                'already_applied' => false,
                'app_delta' => 0,
                'api_delta' => 0,
                'provider_cycle_key' => $providerCycleKey,
            ];
        }

        $cycleStartedAt = $this->normalizeDate(data_get($meta, 'cycle_started_at')) ?? now();
        $cycleEndsAt = $this->normalizeDate(data_get($meta, 'cycle_ends_at'));
        $billingCycle = strtolower(trim((string) data_get($meta, 'billing_cycle', 'monthly')));
        $walletCycleKey = $this->walletCycleKey($cycleStartedAt, $billingCycle);
        $referenceCode = (string) ($meta['reference_code'] ?? 'provider_renewal:'.$subscriptionId.':'.sha1($providerCycleKey));

        return DB::transaction(function () use (
            $subscriptionId,
            $providerCycleKey,
            $cycleStartedAt,
            $cycleEndsAt,
            $billingCycle,
            $walletCycleKey,
            $meta,
            $referenceCode
        ) {
            $lockedPayment = null;
            $candidate = CustomerServiceSubscription::find($subscriptionId);
            if ($candidate) {
                Customer::whereKey($candidate->customer_id)->lockForUpdate()->firstOrFail();
                if ($candidate->payment_id) {
                    $lockedPayment = Payment::whereKey($candidate->payment_id)->lockForUpdate()->firstOrFail();
                }
            }
            /** @var CustomerServiceSubscription|null $lockedSubscription */
            $lockedSubscription = CustomerServiceSubscription::query()
                ->with('servicePlan:id,code,name,monthly_credits,app_monthly_credits,api_monthly_credits,is_active')
                ->lockForUpdate()
                ->find($subscriptionId);

            if (! $lockedSubscription instanceof CustomerServiceSubscription) {
                return [
                    'applied' => false,
                    'already_applied' => false,
                    'app_delta' => 0,
                    'api_delta' => 0,
                    'provider_cycle_key' => $providerCycleKey,
                ];
            }

            $policy = app(SubscriptionCyclePolicy::class);
            $payment = $lockedPayment;
            $lockedSubscription->setRelation('payment', $payment);
            $noop = ['applied' => false, 'already_applied' => false, 'app_delta' => 0, 'api_delta' => 0, 'provider_cycle_key' => $providerCycleKey];
            if (! $policy->isCurrent($lockedSubscription) || $lockedSubscription->status !== 'active'
                || ! $policy->verifiedCollection($payment)
                || (int) $payment->customer_id !== (int) $lockedSubscription->customer_id
                || $payment->purchasable_type !== ServicePlan::class
                || (int) $payment->purchasable_id !== (int) $lockedSubscription->service_plan_id
                || $payment->providerRecurringCycleKey() !== $providerCycleKey
                || ! $cycleStartedAt->equalTo($payment->last_payment_at)
                || ! $cycleEndsAt?->equalTo($payment->active_until)
                || ! $cycleEndsAt->isFuture()) {
                return $noop;
            }
            $claim = SubscriptionCreditAllocation::where('payment_id', $payment->id)
                ->where('cycle_key', $providerCycleKey)->lockForUpdate()->first();
            if ($claim?->applied_at) {
                return array_replace($noop, ['already_applied' => true]);
            }
            if ($lockedSubscription->canceled_at || data_get($payment->meta, 'provider_cancellation.requested_at')
                || in_array(strtoupper((string) $payment->provider_subscription_status), ['CANCELLED', 'CANCELED'], true)) {
                return $noop;
            }
            $latest = SubscriptionCreditAllocation::where('payment_id', $payment->id)
                ->whereIn('allocation_type', ['initial', 'provider_renewal'])->whereNotNull('applied_at')
                ->orderByDesc('cycle_started_at')->lockForUpdate()->first()?->cycle_started_at;
            $appliedThrough = data_get($lockedSubscription->meta, 'last_allocated_payment_at')
                ?? data_get($lockedSubscription->meta, 'provider_last_payment_at');
            if (($latest && $cycleStartedAt->lte(Carbon::parse($latest)))
                || ($appliedThrough && $cycleStartedAt->lte(Carbon::parse($appliedThrough)))) {
                return $noop;
            }
            $subscriptionMeta = (array) ($lockedSubscription->meta ?? []);

            if ((string) data_get($subscriptionMeta, 'last_applied_renewal_cycle_key', '') === $providerCycleKey) {
                return [
                    'applied' => false,
                    'already_applied' => true,
                    'app_delta' => 0,
                    'api_delta' => 0,
                    'provider_cycle_key' => $providerCycleKey,
                ];
            }

            $plan = $lockedSubscription->servicePlan;

            if (! $plan instanceof ServicePlan || ! (bool) ($plan->is_active ?? false)) {
                return [
                    'applied' => false,
                    'already_applied' => false,
                    'app_delta' => 0,
                    'api_delta' => 0,
                    'provider_cycle_key' => $providerCycleKey,
                ];
            }

            $claim = SubscriptionCreditAllocation::create([
                'customer_id' => $lockedSubscription->customer_id, 'subscription_id' => $subscriptionId,
                'payment_id' => $payment->id, 'cycle_key' => $providerCycleKey,
                'allocation_type' => 'provider_renewal', 'cycle_started_at' => $cycleStartedAt,
                'paid_through' => $cycleEndsAt,
            ]);
            $wallet = $this->lockWallet((int) $lockedSubscription->customer_id, CreditWallet::TYPE_APP);
            $apiWallet = $this->lockWallet((int) $lockedSubscription->customer_id, CreditWallet::TYPE_API);

            $appDelta = $this->resetWalletToPlanCycle(
                wallet: $wallet,
                targetSubscriptionBalance: max(0, (int) $plan->appMonthlyCredits()),
                cycleStartedAt: $cycleStartedAt,
                cycleEndsAt: $cycleEndsAt,
                walletCycleKey: $walletCycleKey,
            );

            $apiDelta = $this->resetWalletToPlanCycle(
                wallet: $apiWallet,
                targetSubscriptionBalance: max(0, (int) $plan->apiMonthlyCredits()),
                cycleStartedAt: $cycleStartedAt,
                cycleEndsAt: $cycleEndsAt,
                walletCycleKey: $walletCycleKey,
            );

            $lockedSubscription->forceFill([
                'cycle_started_on' => $cycleStartedAt->toDateString(),
                'cycle_ends_on' => $cycleEndsAt?->toDateString(),
                'next_renewal_on' => $cycleEndsAt?->toDateString(),
                'meta' => array_merge($subscriptionMeta, [
                    'billing_cycle' => $billingCycle,
                    'provider_cycle_key' => $providerCycleKey,
                    'provider_last_payment_at' => data_get($meta, 'provider_last_payment_at'),
                    'last_allocated_payment_at' => $cycleStartedAt->toIso8601String(),
                    'last_applied_renewal_cycle_key' => $providerCycleKey,
                    'last_applied_renewal_at' => now()->toIso8601String(),
                    'last_applied_renewal_source' => data_get($meta, 'source'),
                ]),
            ])->save();

            $ledgerMeta = array_merge($meta, [
                'provider_cycle_key' => $providerCycleKey,
                'subscription_id' => (int) $lockedSubscription->id,
                'service_plan_id' => (int) $plan->id,
                'service_plan_code' => (string) $plan->code,
                'billing_cycle' => $billingCycle,
                'cycle_started_at' => $cycleStartedAt->toIso8601String(),
                'cycle_ends_at' => $cycleEndsAt?->toIso8601String(),
                'reference_code' => $referenceCode,
            ]);

            if ($appDelta['credits_delta'] !== 0) {
                $this->writeLedger(
                    customerId: (int) $lockedSubscription->customer_id,
                    walletType: CreditWallet::TYPE_APP,
                    type: 'provider_subscription_renewal',
                    direction: $appDelta['credits_delta'] < 0 ? 'debit' : 'credit',
                    sourceType: (string) ($meta['source_type'] ?? 'subscription_refill'),
                    bucket: 'combined',
                    creditsDelta: $appDelta['credits_delta'],
                    amount: abs($appDelta['credits_delta']),
                    balanceBefore: $appDelta['balance_before'],
                    wallet: $wallet,
                    referenceCode: $referenceCode,
                    meta: array_merge($ledgerMeta, [
                        'wallet_type' => CreditWallet::TYPE_APP,
                        'previous_subscription_balance' => $appDelta['subscription_before'],
                    ]),
                );
            }

            if ($apiDelta['credits_delta'] !== 0) {
                $this->writeLedger(
                    customerId: (int) $lockedSubscription->customer_id,
                    walletType: CreditWallet::TYPE_API,
                    type: 'provider_subscription_renewal',
                    direction: $apiDelta['credits_delta'] < 0 ? 'debit' : 'credit',
                    sourceType: (string) ($meta['source_type'] ?? 'subscription_refill'),
                    bucket: 'combined',
                    creditsDelta: $apiDelta['credits_delta'],
                    amount: abs($apiDelta['credits_delta']),
                    balanceBefore: $apiDelta['balance_before'],
                    wallet: $apiWallet,
                    referenceCode: 'api:'.$referenceCode,
                    meta: array_merge($ledgerMeta, [
                        'wallet_type' => CreditWallet::TYPE_API,
                        'previous_subscription_balance' => $apiDelta['subscription_before'],
                    ]),
                );
            }

            $claim->update(['status' => 'applied', 'applied_at' => now()]);

            return [
                'applied' => true,
                'already_applied' => false,
                'app_delta' => $appDelta['credits_delta'],
                'api_delta' => $apiDelta['credits_delta'],
                'provider_cycle_key' => $providerCycleKey,
            ];
        }, 3);
    }

    protected function lockWallet(int $customerId, string $walletType): CreditWallet
    {
        $wallet = CreditWallet::query()
            ->where('customer_id', $customerId)
            ->where('wallet_type', $walletType)
            ->lockForUpdate()
            ->first();

        if ($wallet instanceof CreditWallet) {
            return $wallet;
        }

        CreditWallet::query()->create(CreditWallet::defaultAttributes($customerId, $walletType));

        return CreditWallet::query()
            ->where('customer_id', $customerId)
            ->where('wallet_type', $walletType)
            ->lockForUpdate()
            ->firstOrFail();
    }

    protected function writeLedger(
        int $customerId,
        string $walletType,
        string $type,
        string $direction,
        string $sourceType,
        string $bucket,
        int $creditsDelta,
        int $amount,
        int $balanceBefore,
        CreditWallet $wallet,
        string $referenceCode,
        array $meta,
    ): void {
        $toolAction = (string) ($meta['tool_action'] ?? '');
        $toolCode = (string) ($meta['tool_code'] ?? ($toolAction !== '' ? explode('.', $toolAction)[0] : ''));

        CreditLedger::create([
            'customer_id' => $customerId,
            'wallet_type' => $walletType,
            'type' => $type,
            'source_type' => $sourceType,
            'source_id' => isset($meta['source_id']) ? (string) $meta['source_id'] : null,
            'direction' => $direction,
            'amount' => $amount,
            'bucket' => $bucket,
            'credits_delta' => $creditsDelta,
            'balance_before' => $balanceBefore,
            'balance_after' => (int) ($wallet->balance_credits ?? 0),
            'subscription_balance_after' => (int) ($wallet->subscription_balance_credits ?? 0),
            'addon_balance_after' => (int) ($wallet->addon_balance_credits ?? 0),
            'related_type' => $meta['related_type'] ?? null,
            'related_id' => isset($meta['related_id']) ? (string) $meta['related_id'] : null,
            'reference_code' => $referenceCode,
            'tool_code' => $toolCode !== '' ? $toolCode : null,
            'tool_action' => $toolAction !== '' ? $toolAction : null,
            'metric_code' => isset($meta['metric_code']) ? (string) $meta['metric_code'] : null,
            'metric_quantity' => isset($meta['metric_quantity']) ? (float) $meta['metric_quantity'] : null,
            'api_key_id' => isset($meta['api_key_id']) ? (int) $meta['api_key_id'] : null,
            'api_job_id' => isset($meta['api_job_id']) ? (string) $meta['api_job_id'] : null,
            'ml_job_id' => isset($meta['ml_job_id']) ? (string) $meta['ml_job_id'] : null,
            'meta' => $meta,
        ]);
    }

    protected function makeReferenceCode(string $prefix): string
    {
        return strtoupper($prefix).'-'.now()->format('YmdHis').'-'.random_int(1000, 9999);
    }

    /**
     * @return array{credits_delta:int,balance_before:int,subscription_before:int}
     */
    protected function resetWalletToPlanCycle(
        CreditWallet $wallet,
        int $targetSubscriptionBalance,
        CarbonInterface $cycleStartedAt,
        ?CarbonInterface $cycleEndsAt,
        string $walletCycleKey,
    ): array {
        $balanceBefore = (int) ($wallet->balance_credits ?? 0);
        $subscriptionBefore = (int) ($wallet->subscription_balance_credits ?? 0);
        $addonBalance = (int) ($wallet->addon_balance_credits ?? 0);
        $newCombined = $targetSubscriptionBalance + $addonBalance;
        $creditsDelta = $newCombined - $balanceBefore;

        $wallet->subscription_balance_credits = $targetSubscriptionBalance;
        $wallet->addon_balance_credits = $addonBalance;
        $wallet->balance_credits = $newCombined;
        $wallet->lifetime_earned = (int) ($wallet->lifetime_earned ?? 0) + max(0, $targetSubscriptionBalance);
        $wallet->cycle_started_on = $cycleStartedAt->toDateString();
        $wallet->cycle_ends_on = $cycleEndsAt?->toDateString();
        $wallet->current_cycle_key = $walletCycleKey;
        $wallet->last_granted_at = now();
        $wallet->save();

        return [
            'credits_delta' => $creditsDelta,
            'balance_before' => $balanceBefore,
            'subscription_before' => $subscriptionBefore,
        ];
    }

    protected function walletCycleKey(CarbonInterface $cycleStartedAt, string $billingCycle): string
    {
        return match ($billingCycle) {
            'hourly' => $cycleStartedAt->format('Y-m-d-H'),
            'yearly' => $cycleStartedAt->format('Y'),
            default => $cycleStartedAt->format('Y-m'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function syncWalletSubscriptionAllowance(
        int $customerId,
        string $walletType,
        int $targetAllowance,
        int $currentAllowance,
        string $type,
        string $referenceCode,
        array $meta,
        ServicePlan $plan,
        ?CarbonInterface $cycleStartedOn = null,
        ?CarbonInterface $cycleEndsOn = null,
        string $currentCycleKey = '',
    ): array {
        $wallet = $this->lockWallet($customerId, $walletType);
        $balanceBefore = (int) ($wallet->balance_credits ?? 0);
        $subscriptionBefore = (int) ($wallet->subscription_balance_credits ?? 0);
        $addonBefore = (int) ($wallet->addon_balance_credits ?? 0);
        $isUpgrade = $targetAllowance > $currentAllowance;
        $subscriptionAfter = $isUpgrade
            ? $subscriptionBefore + $targetAllowance
            : max($subscriptionBefore, $targetAllowance);
        $addedCredits = max(0, $subscriptionAfter - $subscriptionBefore);

        $wallet->subscription_balance_credits = $subscriptionAfter;
        $wallet->addon_balance_credits = $addonBefore;
        $wallet->syncCombinedBalance();

        if ($addedCredits > 0) {
            $wallet->lifetime_earned = (int) ($wallet->lifetime_earned ?? 0) + $addedCredits;
            $wallet->last_granted_at = now();
        }

        if ($cycleStartedOn) {
            $wallet->cycle_started_on = $cycleStartedOn->toDateString();
        }

        if ($cycleEndsOn) {
            $wallet->cycle_ends_on = $cycleEndsOn->toDateString();
        }

        if ($currentCycleKey !== '') {
            $wallet->current_cycle_key = $currentCycleKey;
        } elseif ($cycleStartedOn) {
            $wallet->current_cycle_key = $cycleStartedOn->format('Y-m');
        }

        $wallet->save();

        if ($addedCredits > 0) {
            $sourceType = (string) ($meta['source_type'] ?? 'admin_credit_sync');
            $context = array_merge($meta, [
                'plan_id' => (int) $plan->id,
                'plan_code' => (string) $plan->code,
                'wallet_type' => $walletType,
                'current_allowance' => $currentAllowance,
                'target_allowance' => $targetAllowance,
                'sync_mode' => $isUpgrade ? 'upgrade' : 'top_up',
                'description' => (string) ($meta['description'] ?? ''),
            ]);

            $this->writeLedger(
                customerId: $customerId,
                walletType: $walletType,
                type: $type,
                direction: 'credit',
                sourceType: $sourceType,
                bucket: 'subscription',
                creditsDelta: $addedCredits,
                amount: $addedCredits,
                balanceBefore: $balanceBefore,
                wallet: $wallet,
                referenceCode: $referenceCode,
                meta: $context,
            );
        }

        return [
            'wallet_type' => $walletType,
            'current_allowance' => $currentAllowance,
            'target_allowance' => $targetAllowance,
            'subscription_before' => $subscriptionBefore,
            'subscription_after' => $subscriptionAfter,
            'addon_after' => $addonBefore,
            'balance_before' => $balanceBefore,
            'balance_after' => (int) ($wallet->balance_credits ?? 0),
            'added_credits' => $addedCredits,
            'changed' => $addedCredits > 0,
            'sync_mode' => $isUpgrade ? 'upgrade' : ($addedCredits > 0 ? 'top_up' : 'no_op'),
        ];
    }

    protected function normalizeDate(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return now()->createFromInterface($value);
        }

        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
