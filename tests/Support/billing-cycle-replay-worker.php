<?php

// Invoked only by the isolated concurrency regression, never against a configured application DB.
require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $database, $subscriptionId, $barrier, $ready] = $argv;
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite'
    || getenv('DB_DATABASE') !== $database || ! str_starts_with(basename($database), 'metkurd-cycle-')
    || realpath(dirname($database)) !== realpath(sys_get_temp_dir())) {
    throw new RuntimeException('Isolated test database required.');
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $database) {
    throw new RuntimeException('Isolated connection required.');
}
Illuminate\Support\Carbon::setTestNow('2026-06-01 10:05:00');
Illuminate\Support\Facades\Notification::fake();
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake();
Illuminate\Support\Facades\Mail::fake();
Illuminate\Support\Facades\DB::statement('PRAGMA busy_timeout = 5000');
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Test barrier timed out.');
    }
    usleep(10000);
}
for ($attempt = 0; $attempt < 20; $attempt++) {
    try {
        $subscription = App\Models\CustomerServiceSubscription::findOrFail($subscriptionId);
        $payment = $subscription->payment;
        if (($argv[5] ?? 'renewal') === 'switch') {
            $new = app(App\Services\Billing\PlanSwitcher::class)->switchServicePlan(
                $subscription->customer, (int) $argv[6], ['provider' => 'admin_manual_grant']);
            $result = ['switched' => $new->id];
        } else {
            $result = app(App\Services\Billing\CreditService::class)->applyProviderRenewalCycle($subscription, [
                'provider_cycle_key' => $payment->providerRecurringCycleKey(),
                'cycle_started_at' => $payment->last_payment_at, 'cycle_ends_at' => $payment->active_until,
                'provider_last_payment_at' => $payment->last_payment_at->toIso8601String(),
                'billing_cycle' => 'monthly', 'source' => 'isolated_race',
            ]);
        }
        file_put_contents($ready.'.result', json_encode($result, JSON_THROW_ON_ERROR));
        exit(0);
    } catch (Throwable $exception) {
        // SQLite reports writer contention instead of MySQL/PostgreSQL row-lock waits.
        if (! str_contains(strtolower($exception->getMessage()), 'locked') || $attempt === 19) {
            throw $exception;
        }
        while (Illuminate\Support\Facades\DB::transactionLevel() > 0) {
            Illuminate\Support\Facades\DB::rollBack();
        }
        usleep(50000);
    }
}
