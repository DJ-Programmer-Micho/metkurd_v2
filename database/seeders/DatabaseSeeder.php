<?php

namespace Database\Seeders;

use Database\Seeders\DevDefaultSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            BillingCurrencyBootstrapSeeder::class,
            PaymentMethodSeeder::class,
            DevDefaultSeeder::class,
            OmniToolSeeder::class,
        ]);
    }
}
