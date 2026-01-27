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
        User::factory()->create([
            'name' => 'Admin Skynet',
            'email' => 'admin@skynet.id',
            'password' => bcrypt('skynet123'),
        ]);

        // Seed in proper order
        $this->call([
            RouterSeeder::class,      // First: Routers
            CustomerSeeder::class,    // Second: Customers (needs packages auto-created)
        ]);

        // Auto-scan network to map customers
        $this->command->info('Running initial network scan...');
        \Illuminate\Support\Facades\Artisan::call('network:monitor'); // Get stats first
        \Illuminate\Support\Facades\Artisan::call('network:scan');    // Then map customers
        $this->command->info('Network scan completed.');
    }
}
