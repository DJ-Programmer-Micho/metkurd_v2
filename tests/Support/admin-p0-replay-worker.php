<?php

// Invoked only by the isolated concurrency regression, never against a configured application DB.
require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $database, $adminId, $customerId, $productId, $operationId, $barrier, $ready] = $argv;
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite'
    || getenv('DB_DATABASE') !== $database || ! str_starts_with(basename($database), 'metkurd-p0-')
    || realpath(dirname($database)) !== realpath(sys_get_temp_dir())) {
    throw new RuntimeException('Isolated test database required.');
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake();
Illuminate\Support\Facades\Mail::fake();
auth('admin')->loginUsingId((int) $adminId);
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
        $result = app(App\Services\Admin\AdminFinancialCorrections::class)->addon(
            $operationId, (int) $customerId, (int) $productId, 'Concurrent isolated replay regression.');
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
