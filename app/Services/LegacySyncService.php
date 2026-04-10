<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LegacySyncService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.legacy_scraper.url', 'http://scraping-ebilling.103.156.128.102.sslip.io');
    }

    /**
     * Sync all data in the correct dependency order.
     */
    public function syncAll(): array
    {
        $stats = [
            'areas' => $this->syncAreas(),
            'packages' => $this->syncPackages(),
            'customers' => $this->syncCustomers(),
            'invoices' => $this->syncInvoices(),
        ];

        return $stats;
    }

    public function syncAreas(): int
    {
        $response = Http::timeout(30)->get("{$this->baseUrl}/api/v1/areas");
        if (!$response->successful()) {
            throw new \Exception("Failed to fetch areas: " . $response->body());
        }

        $areas = $response->json();
        $count = 0;

        foreach ($areas as $data) {
            $normalizedName = $this->normalizeAreaName($data['name']);
            Area::updateOrCreate(
                ['name' => $normalizedName],
                ['code' => Str::slug($normalizedName)]
            );
            $count++;
        }

        return $count;
    }

    public function syncPackages(): int
    {
        $response = Http::timeout(30)->get("{$this->baseUrl}/api/v1/packages");
        if (!$response->successful()) {
            throw new \Exception("Failed to fetch packages: " . $response->body());
        }

        $packages = $response->json();
        $count = 0;

        foreach ($packages as $data) {
            $code = 'PKG-' . strtoupper(substr(md5($data['name'] . time() . uniqid()), 0, 8));
            Package::updateOrCreate(
                ['name' => $data['name']],
                [
                    'code' => $code, // Ensures it passes strict DB constraints
                    'price' => $data['price'] ?? 0,
                ]
            );
            $count++;
        }

        return $count;
    }

    public function syncCustomers(): int
    {
        $response = Http::timeout(60)->get("{$this->baseUrl}/api/v1/customers");
        if (!$response->successful()) {
            throw new \Exception("Failed to fetch customers: " . $response->body());
        }

        $customers = $response->json();
        $count = 0;

        foreach ($customers as $data) {
            $packageId = null;
            if (!empty($data['package'])) {
                $pkg = Package::where('name', $data['package']['name'])->first();
                $packageId = $pkg?->id;
            }

            // Fallback for deleted/orphaned customers
            if (!$packageId) {
                $fallbackPkg = Package::firstOrCreate(
                    ['name' => 'Legacy/Unknown Package'],
                    [
                        'code' => 'PKG-UNKNOWN',
                        'price' => 0,
                    ]
                );
                $packageId = $fallbackPkg->id;
            }

            $areaId = null;
            if (!empty($data['area'])) {
                $normalizedDataAreaName = $this->normalizeAreaName($data['area']['name']);
                $area = Area::where('name', $normalizedDataAreaName)->first();
                $areaId = $area?->id;
            } else {
                // Infer area from package string
                $packageStr = !empty($data['package']) ? $data['package']['name'] : '';
                $inferredAreaName = $this->normalizeAreaName($this->inferAreaFromPackage($packageStr));
                $inferredArea = Area::firstOrCreate(
                    ['name' => $inferredAreaName],
                    ['code' => Str::slug($inferredAreaName)]
                );
                $areaId = $inferredArea->id;
            }

            $joinDate = !empty($data['join_date']) ? Carbon::parse($data['join_date']) : null;

            $pppoeUser = !empty($data['pppoe_user']) ? $data['pppoe_user'] : ($data['id'] . '_PPPOE_' . Str::random(3));
            $existingPppoe = \Illuminate\Support\Facades\DB::table('customers')
                ->where('pppoe_user', $pppoeUser)
                ->where('code', '!=', $data['id'])
                ->exists();
                
            if ($existingPppoe) {
                $pppoeUser = $pppoeUser . '_' . Str::random(3);
            }

            $phone = !empty($data['phone']) ? $data['phone'] : '';
            $address = !empty($data['address']) ? $data['address'] : '-';
            
            $statusRaw = strtolower($data['status'] ?? 'active');
            $validStatuses = ['active', 'suspended', 'inactive', 'isolated', 'terminated', 'pending_installation'];
            if ($statusRaw === 'deleted') {
                $statusRaw = 'terminated';
            } elseif (!in_array($statusRaw, $validStatuses)) {
                $statusRaw = 'active';
            }

            $customer = Customer::withTrashed()->updateOrCreate(
                ['code' => $data['id']], // The ID from legacy system
                [
                    'name' => $data['name'],
                    'nik' => !empty($data['nik']) ? $data['nik'] : null,
                    'address' => $address,
                    'phone' => $phone,
                    'geo_lat' => $data['geo_lat'],
                    'geo_long' => $data['geo_long'],
                    'pppoe_user' => $pppoeUser,
                    'package_id' => $packageId,
                    'area_id' => $areaId,
                    'status' => $statusRaw,
                    'join_date' => $joinDate,
                    'due_day' => $data['due_day'] ?? 20,
                    'ktp_photo_url' => $data['ktp_photo_url'],
                    'is_online' => $data['is_online'] ?? false,
                ]
            );
            
            if (strtolower($data['status'] ?? '') === 'deleted') {
                if (!$customer->trashed()) {
                    $customer->delete();
                }
            } else {
                if ($customer->trashed()) {
                    $customer->restore();
                }
            }
            
            $count++;
        }

        return $count;
    }

    public function syncInvoices(): int
    {
        $response = Http::timeout(60)->get("{$this->baseUrl}/api/v1/invoices");
        if (!$response->successful()) {
            throw new \Exception("Failed to fetch invoices: " . $response->body());
        }

        $invoices = $response->json();
        $count = 0;

        foreach ($invoices as $data) {
            $customer = Customer::where('code', $data['customer_id'])->first();
            if (!$customer) {
                // If customer is missing locally, we cannot assign the invoice.
                Log::warning("Skipping invoice sync for missing customer: {$data['customer_id']}");
                continue;
            }

            $periodDate = Carbon::parse($data['period']);
            $dueDate = $data['due_date'] ? Carbon::parse($data['due_date']) : $periodDate->copy()->addDays(20);

            Invoice::updateOrCreate(
                [
                    'customer_id' => $customer->id,
                    'period' => $periodDate->format('Y-m-d')
                ],
                [
                    'code' => $data['code'] ?? 'INV-' . $periodDate->format('Ym') . '-' . $customer->code,
                    'amount' => $data['amount'],
                    'status' => $data['status'],
                    'due_date' => $dueDate,
                    'generated_at' => $data['generated_at'] ? Carbon::parse($data['generated_at']) : now(),
                    'payment_link' => $data['payment_link'],
                ]
            );
            $count++;
        }

        return $count;
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

    private function normalizeAreaName(string $name): string
    {
        $name = strtoupper(trim($name));
        $name = preg_replace('/\s*-\s*/', '-', $name);
        $name = str_replace('SKYNET ', 'SKYNET-', $name);
        if (!str_starts_with($name, 'SKYNET-') && !str_starts_with($name, 'SUBNET-')) {
            $name = 'SKYNET-' . $name;
        }
        return preg_replace('/\s+/', ' ', $name);
    }
}
