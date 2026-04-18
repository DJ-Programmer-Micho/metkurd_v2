<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateFibFeeConfig(true);
    }

    public function down(): void
    {
        $this->updateFibFeeConfig(false);
    }

    protected function updateFibFeeConfig(bool $passToCustomer): void
    {
        if (! Schema::hasTable('payment_methods')) {
            return;
        }

        $methods = DB::table('payment_methods')
            ->where('code', 'fib')
            ->get(['id', 'fee_config']);

        foreach ($methods as $method) {
            $config = is_array($method->fee_config)
                ? $method->fee_config
                : json_decode((string) $method->fee_config, true);

            if (! is_array($config)) {
                $config = [];
            }

            $defaultRule = $config['default'] ?? [];

            if (! is_array($defaultRule)) {
                $defaultRule = [];
            }

            $defaultRule['percent'] = (float) ($defaultRule['percent'] ?? 1.0);
            $defaultRule['fixed_iqd'] = (int) round((float) ($defaultRule['fixed_iqd'] ?? 0));
            $defaultRule['pass_to_customer'] = $passToCustomer;
            $config['default'] = $defaultRule;

            DB::table('payment_methods')
                ->where('id', $method->id)
                ->update([
                    'fee_config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
        }
    }
};
