<?php

namespace App\Console\Commands;

use App\Enums\PaymentPurposeType;
use App\Models\PaymentMethod;
use Database\Seeders\BillingMasterDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DiagnoseBillingMasterData extends Command
{
    protected $signature = 'metkurd:diagnose-billing-master-data
        {--seed-missing : Seed missing billing master records (safe for customer/payment data)}
        {--force : Force seeding in production when --seed-missing is provided}';

    protected $description = 'Diagnose billing master data required by subscription/add-on checkout flows.';

    public function handle(): int
    {
        if ((bool) $this->option('seed-missing')) {
            $this->comment('Running BillingMasterDataSeeder before diagnostics...');

            $seedExit = $this->call('db:seed', [
                '--class' => BillingMasterDataSeeder::class,
                '--force' => (bool) $this->option('force'),
            ]);

            if ($seedExit !== self::SUCCESS) {
                $this->error('Billing master-data seeding failed. Diagnostics aborted.');

                return self::FAILURE;
            }
        }

        $checks = [];

        $this->checkServicePlans($checks);
        $this->checkStoragePlans($checks);
        $this->checkPaymentMethods($checks);
        $this->checkCurrencies($checks);
        $this->checkCoupons($checks);

        $this->table(
            ['Check', 'Severity', 'Status', 'Details'],
            collect($checks)->map(function (array $check): array {
                return [
                    $check['check'],
                    strtoupper($check['severity']),
                    $check['ok'] ? 'OK' : ($check['severity'] === 'critical' ? 'FAIL' : 'WARN'),
                    $check['details'],
                ];
            })->all()
        );

        $criticalFailures = collect($checks)
            ->where('severity', 'critical')
            ->where('ok', false)
            ->count();

        $warningCount = collect($checks)
            ->where('severity', 'warning')
            ->where('ok', false)
            ->count();

        if ($criticalFailures > 0) {
            $this->error("{$criticalFailures} critical billing master-data check(s) failed.");

            return self::FAILURE;
        }

        if ($warningCount > 0) {
            $this->warn("{$warningCount} warning check(s) need review.");
        }

        $this->info('All critical billing master-data checks passed.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     */
    protected function checkServicePlans(array &$checks): void
    {
        $hasTable = Schema::hasTable('service_plans');
        $this->pushCheck($checks, 'service_plans table exists', $hasTable, 'critical', $hasTable ? 'table is present' : 'table is missing');

        if (! $hasTable) {
            return;
        }

        $activeCount = (int) DB::table('service_plans')->where('is_active', true)->count();
        $freeCount = (int) DB::table('service_plans')
            ->where('is_active', true)
            ->where(function ($query) {
                $query->where('code', 'free')->orWhere('is_free', true);
            })
            ->count();
        $paidCount = (int) DB::table('service_plans')
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('is_free')->orWhere('is_free', false);
            })
            ->count();

        $this->pushCheck(
            $checks,
            'active service plans available',
            $activeCount > 0,
            'critical',
            "active={$activeCount}"
        );
        $this->pushCheck(
            $checks,
            'default/free service plan available',
            $freeCount > 0,
            'critical',
            "free_active={$freeCount}"
        );
        $this->pushCheck(
            $checks,
            'paid service plans available',
            $paidCount > 0,
            'critical',
            "paid_active={$paidCount}"
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     */
    protected function checkStoragePlans(array &$checks): void
    {
        $hasTable = Schema::hasTable('storage_plans');
        $this->pushCheck($checks, 'storage_plans table exists', $hasTable, 'critical', $hasTable ? 'table is present' : 'table is missing');

        if (! $hasTable) {
            return;
        }

        $activeCount = (int) DB::table('storage_plans')->where('is_active', true)->count();
        $freeCount = (int) DB::table('storage_plans')
            ->where('is_active', true)
            ->where(function ($query) {
                $query
                    ->where('code', 'free-512')
                    ->orWhere('price_iqd', 0)
                    ->orWhere('price_usd', 0);
            })
            ->count();
        $paidCount = (int) DB::table('storage_plans')
            ->where('is_active', true)
            ->where(function ($query) {
                $query
                    ->where('price_iqd', '>', 0)
                    ->orWhere('price_usd', '>', 0);
            })
            ->count();

        $this->pushCheck(
            $checks,
            'active storage plans available',
            $activeCount > 0,
            'critical',
            "active={$activeCount}"
        );
        $this->pushCheck(
            $checks,
            'default/free storage plan available',
            $freeCount > 0,
            'critical',
            "free_like_active={$freeCount}"
        );
        $this->pushCheck(
            $checks,
            'paid storage plans available',
            $paidCount > 0,
            'warning',
            "paid_active={$paidCount}"
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     */
    protected function checkPaymentMethods(array &$checks): void
    {
        $hasTable = Schema::hasTable('payment_methods');
        $this->pushCheck($checks, 'payment_methods table exists', $hasTable, 'critical', $hasTable ? 'table is present' : 'table is missing');

        if (! $hasTable) {
            return;
        }

        $methods = PaymentMethod::query()->get();
        $fib = $methods->firstWhere('code', 'fib');
        $areeba = $methods->firstWhere('code', 'areeba');
        $activeVisible = $methods->filter(fn (PaymentMethod $method) => $method->is_active && $method->is_visible)->values();

        $this->pushCheck(
            $checks,
            'FIB payment method row exists',
            $fib instanceof PaymentMethod,
            'critical',
            $fib instanceof PaymentMethod ? 'fib row present' : 'fib row missing'
        );

        $this->pushCheck(
            $checks,
            'Areeba payment method row exists',
            $areeba instanceof PaymentMethod,
            'warning',
            $areeba instanceof PaymentMethod ? 'areeba row present' : 'areeba row missing'
        );

        $serviceCheckoutMethods = $activeVisible
            ->filter(fn (PaymentMethod $method) => $method->supportsPurchaseType(PaymentPurposeType::SERVICE_PLAN))
            ->filter(fn (PaymentMethod $method) => $method->supportsCurrency('IQD'))
            ->pluck('code')
            ->values()
            ->all();
        $serviceRecurringMethods = $activeVisible
            ->filter(fn (PaymentMethod $method) => $method->supportsPurchaseType(PaymentPurposeType::SERVICE_PLAN))
            ->filter(fn (PaymentMethod $method) => $method->supportsCurrency('IQD'))
            ->filter(fn (PaymentMethod $method) => (bool) ($method->supports_recurring ?? false))
            ->pluck('code')
            ->values()
            ->all();
        $storageCheckoutMethods = $activeVisible
            ->filter(fn (PaymentMethod $method) => $method->supportsPurchaseType(PaymentPurposeType::STORAGE_PLAN))
            ->filter(fn (PaymentMethod $method) => $method->supportsCurrency('IQD'))
            ->pluck('code')
            ->values()
            ->all();
        $storageRecurringMethods = $activeVisible
            ->filter(fn (PaymentMethod $method) => $method->supportsPurchaseType(PaymentPurposeType::STORAGE_PLAN))
            ->filter(fn (PaymentMethod $method) => $method->supportsCurrency('IQD'))
            ->filter(fn (PaymentMethod $method) => (bool) ($method->supports_recurring ?? false))
            ->pluck('code')
            ->values()
            ->all();
        $addonCheckoutMethods = $activeVisible
            ->filter(fn (PaymentMethod $method) => $method->supportsPurchaseType(PaymentPurposeType::CREDIT_PRODUCT))
            ->filter(fn (PaymentMethod $method) => $method->supportsCurrency('IQD'))
            ->pluck('code')
            ->values()
            ->all();

        $this->pushCheck(
            $checks,
            'active+visible service-plan checkout method available',
            $serviceCheckoutMethods !== [],
            'critical',
            'methods='.($serviceCheckoutMethods === [] ? '-' : implode(',', $serviceCheckoutMethods))
        );
        $this->pushCheck(
            $checks,
            'active+visible recurring service-plan method available',
            $serviceRecurringMethods !== [],
            'critical',
            'methods='.($serviceRecurringMethods === [] ? '-' : implode(',', $serviceRecurringMethods))
        );
        $this->pushCheck(
            $checks,
            'active+visible storage-plan checkout method available',
            $storageCheckoutMethods !== [],
            'critical',
            'methods='.($storageCheckoutMethods === [] ? '-' : implode(',', $storageCheckoutMethods))
        );
        $this->pushCheck(
            $checks,
            'active+visible recurring storage-plan method available',
            $storageRecurringMethods !== [],
            'critical',
            'methods='.($storageRecurringMethods === [] ? '-' : implode(',', $storageRecurringMethods))
        );
        $this->pushCheck(
            $checks,
            'active+visible add-on checkout method available',
            $addonCheckoutMethods !== [],
            'warning',
            'methods='.($addonCheckoutMethods === [] ? '-' : implode(',', $addonCheckoutMethods))
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     */
    protected function checkCurrencies(array &$checks): void
    {
        $hasCurrenciesTable = Schema::hasTable('currencies');
        $this->pushCheck(
            $checks,
            'currencies table exists',
            $hasCurrenciesTable,
            'critical',
            $hasCurrenciesTable ? 'table is present' : 'table is missing'
        );

        if (! $hasCurrenciesTable) {
            return;
        }

        $iqd = DB::table('currencies')->where('code', 'IQD')->first();
        $usd = DB::table('currencies')->where('code', 'USD')->first();

        $this->pushCheck(
            $checks,
            'IQD currency row exists and is active base',
            $iqd !== null && (bool) ($iqd->is_active ?? false) && (bool) ($iqd->is_base ?? false),
            'critical',
            $iqd ? 'IQD row present' : 'IQD row missing'
        );
        $this->pushCheck(
            $checks,
            'USD currency row exists and is active',
            $usd !== null && (bool) ($usd->is_active ?? false),
            'critical',
            $usd ? 'USD row present' : 'USD row missing'
        );

        $hasRatesTable = Schema::hasTable('currency_exchange_rates');
        $this->pushCheck(
            $checks,
            'currency_exchange_rates table exists',
            $hasRatesTable,
            'critical',
            $hasRatesTable ? 'table is present' : 'table is missing'
        );

        if (! $hasRatesTable) {
            return;
        }

        $iqdUsdRate = DB::table('currency_exchange_rates')
            ->where('base_currency_code', 'IQD')
            ->where('quote_currency_code', 'USD')
            ->where('is_current', true)
            ->orderByDesc('effective_at')
            ->first();

        $this->pushCheck(
            $checks,
            'current IQD->USD anchor rate exists',
            $iqdUsdRate !== null,
            'critical',
            $iqdUsdRate ? 'current rate row present' : 'current rate row missing'
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     */
    protected function checkCoupons(array &$checks): void
    {
        $hasCouponsTable = Schema::hasTable('coupons');

        $this->pushCheck(
            $checks,
            'coupons table exists',
            $hasCouponsTable,
            'warning',
            $hasCouponsTable ? 'table is present' : 'table is missing'
        );

        if (! $hasCouponsTable) {
            return;
        }

        $hasSupportedPaymentMethodsColumn = Schema::hasColumn('coupons', 'supported_payment_methods');
        $this->pushCheck(
            $checks,
            'coupons.supported_payment_methods column exists',
            $hasSupportedPaymentMethodsColumn,
            'critical',
            $hasSupportedPaymentMethodsColumn
                ? 'column is present'
                : 'column is missing (run migrations before checkout)'
        );

        if (! $hasSupportedPaymentMethodsColumn) {
            return;
        }

        $nullSupportCount = (int) DB::table('coupons')
            ->whereNull('supported_payment_methods')
            ->count();

        $this->pushCheck(
            $checks,
            'coupon payment-method compatibility data initialized',
            $nullSupportCount === 0,
            'warning',
            "null_supported_payment_methods={$nullSupportCount}"
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     */
    protected function pushCheck(
        array &$checks,
        string $check,
        bool $ok,
        string $severity,
        string $details
    ): void {
        $checks[] = [
            'check' => $check,
            'severity' => in_array($severity, ['critical', 'warning'], true) ? $severity : 'warning',
            'ok' => $ok,
            'details' => $details,
        ];
    }
}
