<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * onOneServer() requires a shared cache backend (Redis / database / memcached / dynamodb).
 * Local file/array cache cannot coordinate locks across multiple app VMs.
 */
$sharedCacheDrivers = ['redis', 'database', 'memcached', 'dynamodb'];
$sharedSchedulerLocks = in_array((string) config('cache.default'), $sharedCacheDrivers, true);

$applySchedulerGuards = static function ($event, string $name, int $lockMinutes = 10) use ($sharedSchedulerLocks) {
    $event->name($name)->withoutOverlapping($lockMinutes);

    if ($sharedSchedulerLocks) {
        $event->onOneServer();
    }

    return $event;
};

// Stale ML jobs can happen when workers crash/time out mid-flight.
$applySchedulerGuards(
    Schedule::command('ml-jobs:mark-stale-failed --queued-minutes=30 --processing-minutes=60')
        ->everyTenMinutes(),
    'ml-jobs:mark-stale-failed',
    9
);

// Monthly refill is idempotent and safe to run daily.
$applySchedulerGuards(
    Schedule::command('credits:refill-monthly')->dailyAt('00:15'),
    'credits:refill-monthly',
    120
);

if ((bool) config('fib.reconciliation.enabled', true)) {
    $chunkSize = max(10, (int) config('fib.reconciliation.chunk_size', 100));
    $staleMinutes = max(0, (int) config('fib.reconciliation.stale_minutes', 5));
    $graceMinutes = max(0, (int) config('fib.reconciliation.local_expiry_grace_minutes', 0));

    $applySchedulerGuards(Schedule::command(sprintf(
        'payments:reconcile-fib-payments --chunk=%d --stale-minutes=%d',
        $chunkSize,
        $staleMinutes
    ))
        ->everyFiveMinutes(), 'payments:reconcile-fib-payments', 4);

    $applySchedulerGuards(Schedule::command(sprintf(
        'subscriptions:reconcile --chunk=%d --stale-minutes=%d --grace-minutes=%d',
        $chunkSize,
        $staleMinutes,
        $graceMinutes
    ))
        ->everyTenMinutes(), 'subscriptions:reconcile', 9);

    // Keep direct command available for manual/targeted runs.
    // The scheduler above uses subscriptions:reconcile as the single source of truth.
}

$applySchedulerGuards(
    Schedule::command('youtube:cleanup-expired --limit=200')->everyTenMinutes(),
    'youtube:cleanup-expired',
    9
);
