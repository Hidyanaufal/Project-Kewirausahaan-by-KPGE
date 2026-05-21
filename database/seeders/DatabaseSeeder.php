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
        $this->call([
            // UserCompanySeeder::class,
            TaxCodeSeeder::class,
            // AssetMasterDataSeeder::class, // manual only: php artisan db:seed --class=AssetMasterDataSeeder
        ]);
    }
}
