<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use App\Models\Customer;
use App\Models\Package;
use Carbon\Carbon;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $jsonPath = base_path('final_customer_data.json');

        if (!File::exists($jsonPath)) {
            $this->command->error("File not found: {$jsonPath}");
            return;
        }

        $json = File::get($jsonPath);
        $customers = json_decode($json, true);

        if (!$customers) {
            $this->command->error("Failed to decode JSON");
            return;
        }

        $this->command->info("Found " . count($customers) . " customers to process...");

        // Pre-fetch packages to minimize queries
        $packages = Package::all()->keyBy('name');
        
        // Fallback package if mismatch
        $defaultPackage = Package::firstOrCreate(
            ['name' => 'Paket Unknown'],
            ['code' => 'paket-unknown', 'price' => 100000, 'speed_mbps' => 0]
        );

        $count = 0;
        foreach ($customers as $data) {
            $pppoeUser = !empty($data['pppoe_username']) ? trim($data['pppoe_username']) : null;
            $code = !empty($data['code']) ? $data['code'] : 'CUST-' . strtoupper(substr(md5(uniqid()), 0, 6));

            if (!$pppoeUser) {
                // Generate a fallback pppoe_user based on their code or name to satisfy uniqueness
                $pppoeUser = $code . '_PPPOE';
            }

            // Find or Create Package
            $packageName = $data['package'] ?? 'Unknown';
            
            if (!$packages->has($packageName)) {
                $createdPackage = Package::firstOrCreate(
                    ['name' => $packageName],
                    [
                        'code' => \Illuminate\Support\Str::slug($packageName . '-' . ($data['price'] ?? 0)),
                        'price' => $data['price'] ?? 0,
                        'speed_mbps' => (int) filter_var($data['bandwidth'] ?? '0', FILTER_SANITIZE_NUMBER_INT),
                        'mikrotik_profile' => $packageName,
                    ]
                );
                $packages->put($packageName, $createdPackage);
            }
            
            $package = $packages->get($packageName);

            // Map Status
            $status = strtolower($data['status'] ?? 'active');
            if (!in_array($status, ['active', 'suspended', 'inactive', 'isolated', 'terminated', 'pending_installation'])) {
                $status = 'active';
            }

            // Parse Date
            try {
                $joinDate = isset($data['join_date']) 
                    ? Carbon::parse($data['join_date']) 
                    : now();
            } catch (\Exception $e) {
                $joinDate = now();
            }

            if (Customer::where('code', $code)->where('pppoe_user', '!=', $pppoeUser)->exists()) {
                // Code exists for different customer, make it unique
                $code = $code . '-' . substr(md5($pppoeUser), 0, 4);
            }

            Customer::updateOrCreate(
                ['pppoe_user' => $pppoeUser], // Unique Key
                [
                    'code' => $code,
                    'name' => $data['name'] ?? 'Unknown',
                    'address' => $data['address'] ?? '-',
                    'phone' => $data['phone'] ?? null,
                    'nik' => $data['nik'] ?? null,
                    'package_id' => $package->id,
                    'status' => $status,
                    'join_date' => $joinDate,
                    'geo_lat' => (is_numeric($data['latitude']) && abs($data['latitude']) <= 90) ? $data['latitude'] : null,
                    'geo_long' => (is_numeric($data['longitude']) && abs($data['longitude']) <= 180) ? $data['longitude'] : null,
                    // Default password for imports if not specified
                    'pppoe_password' => $data['pppoe_password'] ?? '123456', 
                ]
            );

            $count++;
            if ($count % 100 === 0) {
                $this->command->info("Processed {$count} customers...");
            }
        }

        $this->command->info("Done! Processed {$count} records.");
    }
}
