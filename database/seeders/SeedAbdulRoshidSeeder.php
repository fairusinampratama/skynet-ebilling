<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Package;
use Illuminate\Database\Seeder;

class SeedAbdulRoshidSeeder extends Seeder
{
    public function run()
    {
        $package = Package::firstOrCreate(
            ['name' => 'Demo 5 Mbps Package'],
            [
                'code' => 'PKG-DEMO-5M',
                'price' => 125000,
                'rate_limit' => '5Mbps',
            ]
        );

        $customer = Customer::firstOrCreate(
            ['pppoe_user' => 'demo-customer@example'],
            [
                'code' => 'DEMO001',
                'name' => 'Demo Customer',
                'address' => 'Example service area',
                'phone' => '080000000000',
                'nik' => null,
                'package_id' => $package->id,
                'status' => 'active',
                'join_date' => '2026-01-01',
                'geo_lat' => null,
                'geo_long' => null,
            ]
        );

        $this->command->info('Demo customer seeded successfully.');
    }
}
