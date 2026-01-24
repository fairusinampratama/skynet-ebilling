<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Package;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Load JSON data
        $jsonPath = storage_path('app/private/customer_data.json');
        
        if (!file_exists($jsonPath)) {
            $this->command->error("Customer data file not found at: {$jsonPath}");
            return;
        }

        $customersData = json_decode(file_get_contents($jsonPath), true);
        
        if (!is_array($customersData)) {
            $this->command->error("Invalid JSON format");
            return;
        }

        $this->command->info("Processing " . count($customersData) . " customers...");

        DB::beginTransaction();
        
        try {
            // Track unique packages
            $packageCache = [];
            
            foreach ($customersData as $index => $data) {
                // Skip records with empty pppoe_username
                if (empty($data['pppoe_username'])) {
                    continue;
                }
                
                // Create or get package
                $packageName = $data['package'] ?? 'Unknown Package';
                
                if (!isset($packageCache[$packageName])) {
                    $package = Package::firstOrCreate(
                        ['name' => $packageName],
                        [
                            'price' => $data['price'] ?? 0,
                            'bandwidth_label' => $data['bandwidth'] ?? 'Unknown',
                        ]
                    );
                    $packageCache[$packageName] = $package->id;
                } else {
                    $packageId = $packageCache[$packageName];
                }

                // Parse join_date
                $joinDate = null;
                if (!empty($data['join_date'])) {
                    try {
                        $joinDate = Carbon::createFromFormat('d F Y', $data['join_date'])->format('Y-m-d');
                    } catch (\Exception $e) {
                        // Keep as null if parsing fails
                    }
                }

                // Determine status from JSON
                $status = 'active';
                if (isset($data['status'])) {
                    $status = strtolower($data['status']) === 'active' ? 'active' : 'suspended';
                }

                // Generate default password if not provided
                $pppoePass = $data['pppoe_password'] ?? $data['nik'] ?? 'skynet123';

                // Create or update customer (idempotent)
                Customer::updateOrCreate(
                    ['pppoe_user' => $data['pppoe_username']],
                    [
                        'internal_id' => $data['internal_id'] ?? null,
                        'code' => $data['code'] ?? null,
                        'name' => $data['name'],
                        'address' => $data['address'] ?? '',
                        'phone' => $data['phone'] ?? null,
                        'nik' => $data['nik'] ?? null,
                        'geo_lat' => $data['latitude'] ?? null,
                        'geo_long' => $data['longitude'] ?? null,
                        'pppoe_pass' => $pppoePass,
                        'package_id' => $packageCache[$packageName],
                        'status' => $status,
                        'join_date' => $joinDate,
                        'ktp_photo_url' => $data['ktp_photo_url'] ?? null,
                    ]
                );

                // Progress indicator
                if (($index + 1) % 100 === 0) {
                    $this->command->info("Processed " . ($index + 1) . " customers...");
                }
            }

            DB::commit();
            
            $actualCount = Customer::count();
            $this->command->info("✅ Successfully imported {$actualCount} unique customers (from " . count($customersData) . " records)");
            $this->command->info("📦 Created " . count($packageCache) . " unique packages");
            $this->command->warn("⚠️  Skipped " . (count($customersData) - $actualCount) . " records (duplicates or empty usernames)");
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error("Import failed: " . $e->getMessage());
            throw $e;
        }
    }
}
