<?php

namespace App\Services\CustomerApi;

use App\Models\ApiCreditReservation;
use App\Models\CreditLedger;
use App\Models\CreditWallet;
use Illuminate\Support\Facades\DB;

class CustomerApiCreditReservationService
{
    public function reserve(int $customerId, string $apiJobId, int $amount, array $meta = []): ApiCreditReservation
    {
        if ($amount <= 0) {
            throw new \RuntimeException('Reservation amount must be greater than zero.');
        }

        return DB::transaction(function () use ($customerId, $apiJobId, $amount, $meta): ApiCreditReservation {
            $wallet = $this->lockWallet($customerId);
            $subscription = (int) ($wallet->subscription_balance_credits ?? 0);
            $addon = (int) ($wallet->addon_balance_credits ?? 0);

            if (($subscription + $addon) < $amount) {
                throw new \RuntimeException('Not enough credits.');
            }

            $remaining = $amount;
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
            $wallet->lifetime_spent = (int) ($wallet->lifetime_spent ?? 0) + $amount;
            $wallet->last_charged_at = now();
            $wallet->save();

            $referenceCode = $this->referenceCode('api_reserve');
            $baseMeta = array_merge($meta, [
                'api_job_id' => $apiJobId,
                'wallet_type' => CreditWallet::TYPE_API,
                'reference_code' => $referenceCode,
            ]);

            if ($fromSubscription > 0) {
                $this->writeLedger(
                    customerId: $customerId,
                    type: 'api_reserve',
                    direction: 'reserve',
                    bucket: 'subscription',
                    creditsDelta: -$fromSubscription,
                    amount: $fromSubscription,
                    balanceBefore: $balanceBefore,
                    wallet: $wallet,
                    referenceCode: $referenceCode,
                    meta: array_merge($baseMeta, [
                        'bucket_spent' => 'subscription',
                        'reserved_total' => $amount,
                        'reserved_part' => $fromSubscription,
                    ]),
                );
            }

            if ($fromAddon > 0) {
                $this->writeLedger(
                    customerId: $customerId,
                    type: 'api_reserve',
                    direction: 'reserve',
                    bucket: 'addon',
                    creditsDelta: -$fromAddon,
                    amount: $fromAddon,
                    balanceBefore: $balanceBefore,
                    wallet: $wallet,
                    referenceCode: $referenceCode,
                    meta: array_merge($baseMeta, [
                        'bucket_spent' => 'addon',
                        'reserved_total' => $amount,
                        'reserved_part' => $fromAddon,
                    ]),
                );
            }

            return ApiCreditReservation::create([
                'customer_id' => $customerId,
                'api_job_id' => $apiJobId,
                'amount' => $amount,
                'status' => 'reserved',
                'meta' => array_merge($baseMeta, [
                    'breakdown' => [
                        'subscription' => $fromSubscription,
                        'addon' => $fromAddon,
                    ],
                ]),
            ]);
        }, 3);
    }

    public function settle(ApiCreditReservation $reservation, ?int $finalAmount = null): ApiCreditReservation
    {
        return DB::transaction(function () use ($reservation, $finalAmount): ApiCreditReservation {
            /** @var ApiCreditReservation $fresh */
            $fresh = ApiCreditReservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if ((string) $fresh->status !== 'reserved') {
                return $fresh;
            }

            $reservedAmount = (int) $fresh->amount;
            $finalAmount ??= $reservedAmount;
            $finalAmount = max(0, $finalAmount);

            if ($finalAmount < $reservedAmount) {
                $this->restoreCredits(
                    customerId: (int) $fresh->customer_id,
                    apiJobId: (string) $fresh->api_job_id,
                    amount: $reservedAmount - $finalAmount,
                    meta: (array) ($fresh->meta ?? []),
                    type: 'api_reservation_release'
                );
            }

            $fresh->status = 'settled';
            $fresh->settled_at = now();
            $fresh->meta = array_merge((array) ($fresh->meta ?? []), [
                'final_amount' => $finalAmount,
            ]);
            $fresh->save();

            return $fresh;
        }, 3);
    }

    public function release(ApiCreditReservation $reservation): ApiCreditReservation
    {
        return DB::transaction(function () use ($reservation): ApiCreditReservation {
            /** @var ApiCreditReservation $fresh */
            $fresh = ApiCreditReservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if ((string) $fresh->status !== 'reserved') {
                return $fresh;
            }

            $this->restoreCredits(
                customerId: (int) $fresh->customer_id,
                apiJobId: (string) $fresh->api_job_id,
                amount: (int) $fresh->amount,
                meta: (array) ($fresh->meta ?? []),
                type: 'api_reservation_release'
            );

            $fresh->status = 'released';
            $fresh->released_at = now();
            $fresh->save();

            return $fresh;
        }, 3);
    }

    protected function restoreCredits(int $customerId, string $apiJobId, int $amount, array $meta, string $type): void
    {
        if ($amount <= 0) {
            return;
        }

        $wallet = $this->lockWallet($customerId);
        $subscriptionRefund = min((int) data_get($meta, 'breakdown.subscription', 0), $amount);
        $addonRefund = $amount - $subscriptionRefund;
        $balanceBefore = (int) ($wallet->balance_credits ?? 0);

        $wallet->subscription_balance_credits = (int) ($wallet->subscription_balance_credits ?? 0) + $subscriptionRefund;
        $wallet->addon_balance_credits = (int) ($wallet->addon_balance_credits ?? 0) + $addonRefund;
        $wallet->syncCombinedBalance();
        $wallet->lifetime_refunded = (int) ($wallet->lifetime_refunded ?? 0) + $amount;
        $wallet->save();

        if ($subscriptionRefund > 0) {
            $this->writeLedger(
                customerId: $customerId,
                type: $type,
                direction: 'release',
                bucket: 'subscription',
                creditsDelta: $subscriptionRefund,
                amount: $subscriptionRefund,
                balanceBefore: $balanceBefore,
                wallet: $wallet,
                referenceCode: (string) data_get($meta, 'reference_code', $this->referenceCode($type)),
                meta: array_merge($meta, [
                    'api_job_id' => $apiJobId,
                    'wallet_type' => CreditWallet::TYPE_API,
                    'refunded_part' => $subscriptionRefund,
                ]),
            );
        }

        if ($addonRefund > 0) {
            $this->writeLedger(
                customerId: $customerId,
                type: $type,
                direction: 'release',
                bucket: 'addon',
                creditsDelta: $addonRefund,
                amount: $addonRefund,
                balanceBefore: $balanceBefore,
                wallet: $wallet,
                referenceCode: (string) data_get($meta, 'reference_code', $this->referenceCode($type)),
                meta: array_merge($meta, [
                    'api_job_id' => $apiJobId,
                    'wallet_type' => CreditWallet::TYPE_API,
                    'refunded_part' => $addonRefund,
                ]),
            );
        }
    }

    protected function lockWallet(int $customerId): CreditWallet
    {
        $wallet = CreditWallet::query()
            ->where('customer_id', $customerId)
            ->where('wallet_type', CreditWallet::TYPE_API)
            ->lockForUpdate()
            ->first();

        if ($wallet instanceof CreditWallet) {
            return $wallet;
        }

        CreditWallet::query()->create(CreditWallet::defaultAttributes($customerId, CreditWallet::TYPE_API));

        return CreditWallet::query()
            ->where('customer_id', $customerId)
            ->where('wallet_type', CreditWallet::TYPE_API)
            ->lockForUpdate()
            ->firstOrFail();
    }

    protected function writeLedger(
        int $customerId,
        string $type,
        string $direction,
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
            'wallet_type' => CreditWallet::TYPE_API,
            'type' => $type,
            'source_type' => (string) ($meta['source_type'] ?? 'public_api'),
            'source_id' => isset($meta['source_id']) ? (string) $meta['source_id'] : (string) ($meta['api_job_id'] ?? null),
            'direction' => $direction,
            'amount' => $amount,
            'bucket' => $bucket,
            'credits_delta' => $creditsDelta,
            'balance_before' => $balanceBefore,
            'balance_after' => (int) ($wallet->balance_credits ?? 0),
            'subscription_balance_after' => (int) ($wallet->subscription_balance_credits ?? 0),
            'addon_balance_after' => (int) ($wallet->addon_balance_credits ?? 0),
            'related_type' => 'api_job',
            'related_id' => isset($meta['api_job_id']) ? (string) $meta['api_job_id'] : null,
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

    protected function referenceCode(string $prefix): string
    {
        return strtoupper($prefix).'-'.now()->format('YmdHis').'-'.random_int(1000, 9999);
    }
}
