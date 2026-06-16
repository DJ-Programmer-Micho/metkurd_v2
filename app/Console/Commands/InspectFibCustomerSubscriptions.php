<?php

namespace App\Console\Commands;

use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Models\Customer;
use Illuminate\Console\Command;

class InspectFibCustomerSubscriptions extends Command
{
    protected $signature = 'payments:fib:inspect-customer-subscriptions
        {customer : Customer id to inspect}';

    protected $description = 'List local FIB recurring service-plan subscription rows for one customer.';

    public function handle(): int
    {
        $customerId = (int) $this->argument('customer');

        $customer = Customer::query()->find($customerId);

        if (! $customer instanceof Customer) {
            $this->error("Customer [{$customerId}] was not found.");

            return self::FAILURE;
        }

        $rows = Payment::query()
            ->where('customer_id', $customerId)
            ->where('provider', PaymentProvider::FIB)
            ->where('payment_mode', PaymentMode::RECURRING)
            ->where('provider_object_type', PaymentProviderObjectType::SUBSCRIPTION)
            ->where('purchase_type', PurchaseType::PLAN_SUBSCRIPTION)
            ->orderByDesc('id')
            ->get();

        $this->info(sprintf(
            'FIB recurring service-plan subscriptions for customer %d (%s)',
            $customerId,
            (string) $customer->username
        ));

        if ($rows->isEmpty()) {
            $this->comment('No matching local FIB recurring service-plan subscriptions were found.');

            return self::SUCCESS;
        }

        $this->table([
            'id',
            'uuid',
            'plan_id',
            'status',
            'internal_status',
            'provider_sub_status',
            'provider_pay_status',
            'fib_subscription_id',
            'paid_at',
            'fulfilled_at',
            'created_at',
        ], $rows->map(static function (Payment $payment): array {
            return [
                'id' => (int) $payment->id,
                'uuid' => (string) $payment->uuid,
                'plan_id' => (int) $payment->purchasable_id,
                'status' => (string) ($payment->status?->value ?? ''),
                'internal_status' => (string) ($payment->internal_status?->value ?? ''),
                'provider_sub_status' => (string) ($payment->provider_subscription_status ?? ''),
                'provider_pay_status' => (string) ($payment->providerPaymentStatusLabel() ?? ''),
                'fib_subscription_id' => (string) ($payment->fib_subscription_id ?? ''),
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'fulfilled_at' => $payment->fulfilled_at?->toIso8601String(),
                'created_at' => $payment->created_at?->toIso8601String(),
            ];
        })->all());

        return self::SUCCESS;
    }
}
