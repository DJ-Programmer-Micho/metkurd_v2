<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('youtube:cleanup-expired')->everyTenMinutes();

if ((bool) config('fib.reconciliation.enabled', true)) {
    $chunkSize = max(10, (int) config('fib.reconciliation.chunk_size', 100));
    $staleMinutes = max(0, (int) config('fib.reconciliation.stale_minutes', 5));

    Schedule::command(sprintf(
        'payments:reconcile-fib-subscriptions --chunk=%d --stale-minutes=%d',
        $chunkSize,
        $staleMinutes
    ))
        ->everyTenMinutes()
        ->withoutOverlapping();
}
