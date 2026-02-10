<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create Admin User
        // Seed in proper order
        $this->call([
            UserSeeder::class,      // Create Admin
            AreaSeeder::class,      // Run first to populate areas
            LegacyDataSeeder::class,  // Import Customers & Invoices
        ]);
    }
}
