<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(BillingMasterDataSeeder::class);

        if (app()->environment(['local', 'testing'])) {
            $this->call(DevDefaultSeeder::class);

            return;
        }

        $this->call([
            OmniToolSeeder::class,
            CaptionToolSeeder::class,
        ]);
    }
}
