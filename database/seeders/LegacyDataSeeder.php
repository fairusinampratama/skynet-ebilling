<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LegacyDataSeeder extends Seeder
{
    private array $packageCache = [];
    private array $customerCache = [];
    private array $areaCache = [];

    public function run(): void
    {
        $this->command->info('🚀 Starting Legacy Data Migration...');

        // $this->importRouters(); // Removed
        $this->importCustomers();
        $this->importTransactions();

        $this->command->info('✅ Migration completed successfully!');
    }

    private function importCustomers(): void
    {
        $this->command->info('👥 Step 2: Importing Customers & Packages (Unified Final Data)...');

        $jsonPath = database_path('../migration_data/customers_final.json');
        if (!file_exists($jsonPath)) {
            $this->command->error("File not found: $jsonPath");
            return;
        }

        $customersJson = json_decode(file_get_contents($jsonPath), true);
        $bar = $this->command->getOutput()->createProgressBar(count($customersJson));
        $bar->start();

        $this->areaCache = \App\Models\Area::pluck('id', 'name')->toArray();

        foreach ($customersJson as $data) {
            try {
                $customerId = $data['id_pelanggan'];

                // Find Area ID (Direct or Inferred)
                $areaName = $data['nama_lokasi'] ?? $this->inferAreaFromPackage($data['paket'] ?? '');
                $areaId = $this->areaCache[$areaName] ?? null;

                // Find or create package
                $package = $this->findOrCreatePackage($data);

                // Coordinate parsing
                [$lat, $long] = $this->parseCoordinates($data['koordinat'] ?? '');

                // Map status
                $status = $this->mapStatus($data['connection_status'] ?? 'Active');

                // Parse join_date
                $joinDate = $this->parseJoinDate($data['tanggal_registrasi'] ?? null);

                // Create or update customer
                $customer = Customer::updateOrCreate(
                    ['code' => $customerId],
                    [
                        'name' => $data['nama_pelanggan'],
                        'address' => $data['alamat'] ?? null,
                        'phone' => $data['telepon'] ?? null,
                        'nik' => $data['nik'] ?? null,
                        'pppoe_user' => !empty($data['pppoe_username']) ? $data['pppoe_username'] : ($customerId . '_PPPOE'),
                        'package_id' => $package->id,
                        'area_id' => $areaId,
                        'status' => $status,
                        'geo_lat' => $lat,
                        'geo_long' => $long,
                        'join_date' => $joinDate,
                        'due_day' => max(1, min(28, (int) ($data['jatuh_tempo'] ?? 20))),
                        'ktp_photo_url' => !empty($data['ktp_photo_url']) ? $data['ktp_photo_url'] : null,
                    ]
                );

                $this->customerCache[$customerId] = $customer;
            } catch (\Exception $e) {
                $this->command->newLine();
                $this->command->error("Failed to import customer {$data['id_pelanggan']}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info("✅ Customers: " . count($this->customerCache) . " imported");
    }

    private function importTransactions(): void
    {
        $this->command->info('💰 Step 3: Importing Invoices & Transactions...');

        $transactionsJson = json_decode(file_get_contents(database_path('../migration_data/transactions.json')), true);
        $bar = $this->command->getOutput()->createProgressBar(count($transactionsJson));
        $bar->start();

        $invoiceCount = 0;
        $transactionCount = 0;

        foreach ($transactionsJson as $data) {
            try {
                // Find customer
                $customer = $this->customerCache[$data['id_pelanggan']] ?? Customer::where('code', $data['id_pelanggan'])->first();

                if (!$customer) {
                    continue; // Skip if customer doesn't exist
                }

                // Parse period (e.g., "July 2021" -> "2021-07-01")
                $period = $this->parsePeriod($data['periode']);

                // Map status
                $invoiceStatus = strtolower($data['status_pembayaran']) === 'lunas' ? 'paid' : 'unpaid';

                // Find or update invoice
                $invoice = Invoice::updateOrCreate(
                    [
                        'customer_id' => $customer->id,
                        'period' => $period,
                    ],
                    [
                        'amount' => $data['nominal_harus_dibayar'],
                        'status' => $invoiceStatus,
                        'due_date' => Carbon::parse($period)->addDays(28), // Default to 28 days
                        'generated_at' => now(),
                    ]
                );

                // Ensure code exists if newly created or missing
                if (empty($invoice->code)) {
                    $invoice->update(['code' => $this->generateInvoiceCode($customer, $period)]);
                }

                $invoiceCount++;

                // Create transaction if paid
                if ($invoiceStatus === 'paid' && $data['nominal_pembayaran'] > 0) {
                    // Get first admin or null
                    $adminId = User::first()?->id;

                    Transaction::firstOrCreate([
                        'invoice_id' => $invoice->id,
                        'amount' => $data['nominal_pembayaran'],
                        'paid_at' => $this->parsePaymentDate($data['waktu_entry']),
                    ], [
                        'method' => $this->mapPaymentMethod($data['metode']),
                        'status' => 'paid',
                        'admin_id' => $adminId,
                        'proof_url' => $data['bukti_pembayaran_url'] ?? null,
                    ]);
                   
                    $transactionCount++;
                }
            } catch (\Exception $e) {
                $this->command->newLine();
                $this->command->error("Failed to import transaction for {$data['id_pelanggan']}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info("✅ Invoices: {$invoiceCount} imported");
        $this->command->info("✅ Transactions: {$transactionCount} imported");
    }

    // HELPER METHODS

    private function findOrCreatePackage(array $customerData): Package
    {
        $packageName = $customerData['paket'] ?? 'Default';
        $price = $customerData['harga'] ?? 0;

        // Cache key
        $cacheKey = "{$packageName}:{$price}";

        if (isset($this->packageCache[$cacheKey])) {
            return $this->packageCache[$cacheKey];
        }

        // Find or create package
        $package = Package::firstOrCreate(
            [
                'name' => $packageName,
                'price' => $price,
            ],
            [
                'mikrotik_profile' => $packageName, 
                'rate_limit' => null, 
            ]
        );

        $this->packageCache[$cacheKey] = $package;

        return $package;
    }

    private function inferAreaFromPackage(string $packageName): string
    {
        $name = strtoupper($packageName);
        
        if (str_contains($name, 'KRIAN')) return 'SKYNET-KRIAN';
        if (str_contains($name, 'WAJAK')) return 'SKYNET-WAJAK';
        if (str_contains($name, 'BUMIAYU')) return 'SKYNET-BUMIAYU';
        if (str_contains($name, 'KENDIT')) return 'SKYNET-KENDIT';
        if (str_contains($name, 'PASURUAN')) return 'SKYNET-PASURUAN';
        if (str_contains($name, 'MALANG')) return 'SKYNET-MALANG';
        if (str_contains($name, 'BLITAR')) return 'SKYNET-BLITAR';
        if (str_contains($name, 'MARTOPURO')) return 'SKYNET-MARTOPURO';
        if (str_contains($name, 'COMBORAN')) return 'SKYNET-COMBORAN';
        if (str_contains($name, 'PUROWOSARI')) return 'SKYNET-PURWOSARI';
        
        return 'SKYNET-GENERAL';
    }

    private function parseCoordinates(string $koordinat): array
    {
        if (empty($koordinat)) {
            return [null, null];
        }

        $parts = explode(',', $koordinat);
        if (count($parts) !== 2) {
            return [null, null];
        }

        $lat = $this->sanitizeCoordinate(trim($parts[0]), -90, 90);
        $long = $this->sanitizeCoordinate(trim($parts[1]), -180, 180);

        return [$lat, $long];
    }

    private function sanitizeCoordinate(?string $value, float $min, float $max): ?string
    {
        if (empty($value)) {
            return null;
        }

        // Remove letters (S, N, E, W, etc.) and spaces
        $value = preg_replace('/[A-Za-z\s]/', '', $value);

        // Check if value is logically far out of range (e.g. -7874458.0 instead of -7.874458)
        $float = (float) $value;
        if ($float < $min || $float > $max) {
             // Strip all decimals and re-place after first digit
             $clean = str_replace('.', '', $value);
             if (strlen(ltrim($clean, '-')) > 2) {
                 $isNegative = str_starts_with($clean, '-');
                 $absValue = ltrim($clean, '-');
                 $value = ($isNegative ? '-' : '') . $absValue[0] . '.' . substr($absValue, 1);
             }
        }

        // Handle double decimal points remaining
        if (substr_count($value, '.') > 1) {
            $parts = explode('.', $value);
            $value = $parts[0] . '.' . $parts[1];
        }

        // Validate it's a number
        if (!is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        // Final range check
        if ($float < $min || $float > $max) {
            return null;
        }

        return (string) $float;
    }

    private function mapStatus(string $status): string
    {
        return match (strtolower($status)) {
            'active' => 'active',
            'isolated' => 'isolated',
            'terminated' => 'terminated',
            default => 'pending_installation',
        };
    }

    private function parseJoinDate(?string $date): ?Carbon
    {
        if (!$date) {
            return null;
        }

        try {
            // Parse "01-February-2026"
            return Carbon::createFromFormat('d-F-Y', $date);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function parsePeriod(string $periode): string
    {
        try {
            // Parse "July 2021" -> "2021-07-01"
            return Carbon::createFromFormat('F Y', $periode)->startOfMonth()->toDateString();
        } catch (\Exception $e) {
            // Fallback
            return now()->startOfMonth()->toDateString();
        }
    }

    private function mapPaymentMethod(string $metode): string
    {
        $method = strtolower($metode);

        if (str_contains($method, 'cash') || str_contains($method, 'tunai')) {
            return 'cash';
        }

        if (str_contains($method, 'bca') || str_contains($method, 'bni') || str_contains($method, 'mandiri')) {
            return 'transfer';
        }

        return 'cash'; // Default
    }

    private function parsePaymentDate(string $waktuEntry): Carbon
    {
        try {
            // Parse "25-07-2022 03:56:34 AM"
            return Carbon::createFromFormat('d-m-Y h:i:s A', $waktuEntry);
        } catch (\Exception $e) {
            return now();
        }
    }

    private function extractBandwidth(string $packageName): string
    {
        // Extract bandwidth from package name (e.g., "Paket 10Mb" -> "10 Mbps")
        if (preg_match('/(\d+)\s*(mb|mbps|m)/i', $packageName, $matches)) {
            return $matches[1] . ' Mbps';
        }

        return 'Unknown';
    }

    private function generateInvoiceCode(Customer $customer, string $period): string
    {
        // INV-YYYYMM-CUSTCODE-RAND
        $date = Carbon::parse($period);
        $prefix = 'INV-' . $date->format('Ym');
        $random = strtoupper(\Illuminate\Support\Str::random(4));
        return "{$prefix}-{$customer->code}-{$random}";
    }
}
