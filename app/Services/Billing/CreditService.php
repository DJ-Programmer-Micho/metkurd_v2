<?php

namespace App\Services\Billing;

use App\Models\CreditLedger;
use App\Models\CreditWallet;
use Illuminate\Support\Facades\DB;

class CreditService
{
    protected function syncCombinedBalance(CreditWallet $wallet): void
    {
        $wallet->balance_credits =
            (int) ($wallet->subscription_balance_credits ?? 0)
            + (int) ($wallet->addon_balance_credits ?? 0);
    }

    public function charge(int $customerId, int $credits, string $type, array $meta = []): void
    {
        if ($credits <= 0) {
            return;
        }

        DB::transaction(function () use ($customerId, $credits, $type, $meta) {
            /** @var CreditWallet $wallet */
            $wallet = CreditWallet::query()
                ->lockForUpdate()
                ->where('customer_id', $customerId)
                ->firstOrFail();

            $subscription = (int) ($wallet->subscription_balance_credits ?? 0);
            $addon = (int) ($wallet->addon_balance_credits ?? 0);

            $bucketCombined = $subscription + $addon;
            $storedCombined = (int) ($wallet->balance_credits ?? 0);

            if ($bucketCombined <= 0 && $storedCombined > 0) {
                $addon = $storedCombined;

                $wallet->subscription_balance_credits = $subscription;
                $wallet->addon_balance_credits = $addon;
                $this->syncCombinedBalance($wallet);
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

            $wallet->subscription_balance_credits = $subscription;
            $wallet->addon_balance_credits = $addon;
            $this->syncCombinedBalance($wallet);

            $combined = (int) $wallet->balance_credits;

            $wallet->lifetime_spent = (int) $wallet->lifetime_spent + $credits;
            $wallet->last_charged_at = now();
            $wallet->save();

            $referenceCode = $meta['reference_code'] ?? $this->makeReferenceCode($type);

            if ($fromSubscription > 0) {
                CreditLedger::create([
                    'customer_id' => $customerId,
                    'type' => $type,
                    'bucket' => 'subscription',
                    'credits_delta' => -$fromSubscription,
                    'balance_after' => $combined,
                    'subscription_balance_after' => $subscription,
                    'addon_balance_after' => $addon,
                    'related_type' => $meta['related_type'] ?? null,
                    'related_id' => isset($meta['related_id']) ? (string) $meta['related_id'] : null,
                    'reference_code' => $referenceCode,
                    'meta' => array_merge($meta, [
                        'bucket_spent' => 'subscription',
                        'charged_total' => $credits,
                        'charged_part' => $fromSubscription,
                    ]),
                ]);
            }

            if ($fromAddon > 0) {
                CreditLedger::create([
                    'customer_id' => $customerId,
                    'type' => $type,
                    'bucket' => 'addon',
                    'credits_delta' => -$fromAddon,
                    'balance_after' => $combined,
                    'subscription_balance_after' => $subscription,
                    'addon_balance_after' => $addon,
                    'related_type' => $meta['related_type'] ?? null,
                    'related_id' => isset($meta['related_id']) ? (string) $meta['related_id'] : null,
                    'reference_code' => $referenceCode,
                    'meta' => array_merge($meta, [
                        'bucket_spent' => 'addon',
                        'charged_total' => $credits,
                        'charged_part' => $fromAddon,
                    ]),
                ]);
            }
        }, 3);
    }

    public function refund(int $customerId, int $credits, string $type, array $meta = []): void
    {
        if ($credits <= 0) {
            return;
        }

        DB::transaction(function () use ($customerId, $credits, $type, $meta) {
            /** @var CreditWallet $wallet */
            $wallet = CreditWallet::query()
                ->lockForUpdate()
                ->where('customer_id', $customerId)
                ->firstOrFail();

            $refundBucket = $meta['refund_bucket']
                ?? $meta['bucket']
                ?? 'addon';

            $subscription = (int) ($wallet->subscription_balance_credits ?? 0);
            $addon = (int) ($wallet->addon_balance_credits ?? 0);

            if ($refundBucket === 'subscription') {
                $subscription += $credits;
            } else {
                $refundBucket = 'addon';
                $addon += $credits;
            }

            $wallet->subscription_balance_credits = $subscription;
            $wallet->addon_balance_credits = $addon;
            $this->syncCombinedBalance($wallet);

            $combined = (int) $wallet->balance_credits;

            $wallet->lifetime_refunded = (int) ($wallet->lifetime_refunded ?? 0) + $credits;
            $wallet->save();

            CreditLedger::create([
                'customer_id' => $customerId,
                'type' => $type,
                'bucket' => $refundBucket,
                'credits_delta' => $credits,
                'balance_after' => $combined,
                'subscription_balance_after' => $subscription,
                'addon_balance_after' => $addon,
                'related_type' => $meta['related_type'] ?? null,
                'related_id' => isset($meta['related_id']) ? (string) $meta['related_id'] : null,
                'reference_code' => $meta['reference_code'] ?? $this->makeReferenceCode($type),
                'meta' => $meta,
            ]);
        }, 3);
    }

    public function grantMonthlyCredits(int $customerId, int $credits, array $meta = []): void
    {
        if ($credits <= 0) {
            return;
        }

        DB::transaction(function () use ($customerId, $credits, $meta) {
            /** @var CreditWallet $wallet */
            $wallet = CreditWallet::query()
                ->lockForUpdate()
                ->where('customer_id', $customerId)
                ->firstOrFail();

            $subscription = (int) ($wallet->subscription_balance_credits ?? 0) + $credits;
            $addon = (int) ($wallet->addon_balance_credits ?? 0);

            $wallet->subscription_balance_credits = $subscription;
            $wallet->addon_balance_credits = $addon;
            $this->syncCombinedBalance($wallet);

            $combined = (int) $wallet->balance_credits;

            $wallet->lifetime_earned = (int) $wallet->lifetime_earned + $credits;
            $wallet->last_granted_at = now();
            $wallet->save();

            CreditLedger::create([
                'customer_id' => $customerId,
                'type' => 'monthly_grant',
                'bucket' => 'subscription',
                'credits_delta' => $credits,
                'balance_after' => $combined,
                'subscription_balance_after' => $subscription,
                'addon_balance_after' => $addon,
                'related_type' => $meta['related_type'] ?? null,
                'related_id' => isset($meta['related_id']) ? (string) $meta['related_id'] : null,
                'reference_code' => $meta['reference_code'] ?? $this->makeReferenceCode('monthly_grant'),
                'meta' => $meta,
            ]);
        }, 3);
    }

    public function addAddonCredits(int $customerId, int $credits, array $meta = []): void
    {
        if ($credits <= 0) {
            return;
        }

        DB::transaction(function () use ($customerId, $credits, $meta) {
            /** @var CreditWallet $wallet */
            $wallet = CreditWallet::query()
                ->lockForUpdate()
                ->where('customer_id', $customerId)
                ->firstOrFail();

            $subscription = (int) ($wallet->subscription_balance_credits ?? 0);
            $addon = (int) ($wallet->addon_balance_credits ?? 0) + $credits;

            $wallet->subscription_balance_credits = $subscription;
            $wallet->addon_balance_credits = $addon;
            $this->syncCombinedBalance($wallet);

            $combined = (int) $wallet->balance_credits;

            $wallet->lifetime_earned = (int) $wallet->lifetime_earned + $credits;
            $wallet->save();

            CreditLedger::create([
                'customer_id' => $customerId,
                'type' => 'addon_purchase',
                'bucket' => 'addon',
                'credits_delta' => $credits,
                'balance_after' => $combined,
                'subscription_balance_after' => $subscription,
                'addon_balance_after' => $addon,
                'related_type' => $meta['related_type'] ?? null,
                'related_id' => isset($meta['related_id']) ? (string) $meta['related_id'] : null,
                'reference_code' => $meta['reference_code'] ?? $this->makeReferenceCode('addon_purchase'),
                'meta' => $meta,
            ]);
        }, 3);
    }

    protected function makeReferenceCode(string $prefix): string
    {
        return strtoupper($prefix) . '-' . now()->format('YmdHis') . '-' . random_int(1000, 9999);
    }
}