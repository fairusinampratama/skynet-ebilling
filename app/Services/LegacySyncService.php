<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LegacySyncService
{
    protected string $baseUrl;

    public function __construct(private LegacyAreaResolver $areaResolver)
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
        if (! $response->successful()) {
            return $this->syncAreasFromCustomers();
        }

        return collect($response->json())
            ->pluck('name')
            ->filter()
            ->map(fn (string $name) => $this->areaResolver->normalizeAreaName($name))
            ->filter(fn (string $name) => $this->areaResolver->isApprovedArea($name))
            ->unique()
            ->sum(function (string $name) {
                Area::updateOrCreate(
                    ['name' => $name],
                    ['code' => Str::slug($name)]
                );

                return 1;
            });
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
            $code = 'PKG-' . strtoupper(substr(md5($data['name']), 0, 8));
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
        $packagesByName = Package::pluck('id', 'name');
        $areasByName = Area::pluck('id', 'name');
        $fallbackPkg = Package::firstOrCreate(
            ['name' => 'Legacy/Unknown Package'],
            [
                'code' => 'PKG-UNKNOWN',
                'price' => 0,
            ]
        );
        $seenPppoeUsers = [];
        $existingPppoeUsers = Customer::withTrashed()->pluck('code', 'pppoe_user');
        $rows = [];
        $now = now();

        foreach ($customers as $data) {
            $packageId = null;
            if (!empty($data['package'])) {
                $packageId = $packagesByName->get($data['package']['name']);
            }

            if (!$packageId) {
                $packageId = $fallbackPkg->id;
            }

            $areaId = null;
            $resolvedArea = $this->areaResolver->resolve($data);
            if ($resolvedArea['area']) {
                $areaId = $areasByName->get($resolvedArea['area']);
                if (! $areaId) {
                    $area = Area::firstOrCreate(
                        ['name' => $resolvedArea['area']],
                        ['code' => Str::slug($resolvedArea['area'])]
                    );
                    $areaId = $area->id;
                    $areasByName->put($resolvedArea['area'], $areaId);
                }
            } else {
                Log::warning('Legacy customer has no approved area mapping', [
                    'customer_id' => $data['id'] ?? null,
                    'customer_name' => $data['name'] ?? null,
                ]);
            }

            $joinDate = !empty($data['join_date']) ? Carbon::parse($data['join_date']) : null;

            $pppoeUser = !empty($data['pppoe_user']) ? $data['pppoe_user'] : ($data['id'] . '_USR');
            $attempts = 0;
            while (
                (isset($seenPppoeUsers[$pppoeUser]) && $seenPppoeUsers[$pppoeUser] !== $data['id'])
                || ($existingPppoeUsers->has($pppoeUser) && $existingPppoeUsers->get($pppoeUser) !== $data['id'])
            ) {
                $attempts++;
                $pppoeUser = $data['id'] . '_USR_' . ($attempts + 1);
            }
            $seenPppoeUsers[$pppoeUser] = $data['id'];

            $phone = !empty($data['phone']) ? $data['phone'] : '';
            $address = !empty($data['address']) ? $data['address'] : '-';
            
            $statusRaw = strtolower($data['status'] ?? 'active');
            $validStatuses = ['active', 'suspended', 'inactive', 'isolated', 'terminated', 'pending_installation'];
            if ($statusRaw === 'deleted') {
                $statusRaw = 'terminated';
            } elseif (!in_array($statusRaw, $validStatuses)) {
                $statusRaw = 'active';
            }

            $rows[] = [
                'code' => $data['id'],
                'legacy_id' => $data['id'],
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
                'join_date' => $joinDate?->toDateString(),
                'due_day' => $data['due_day'] ?? 20,
                'ktp_photo_url' => $data['ktp_photo_url'],
                'is_online' => $data['is_online'] ?? false,
                'deleted_at' => strtolower($data['status'] ?? '') === 'deleted' ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('customers')->upsert($chunk, ['code'], [
                'legacy_id',
                'name',
                'nik',
                'address',
                'phone',
                'geo_lat',
                'geo_long',
                'pppoe_user',
                'package_id',
                'area_id',
                'status',
                'join_date',
                'due_day',
                'ktp_photo_url',
                'is_online',
                'deleted_at',
                'updated_at',
            ]);
        }

        return count($rows);
    }

    public function syncInvoices(): int
    {
        $response = Http::timeout(60)->get("{$this->baseUrl}/api/v1/invoices");
        if (!$response->successful()) {
            throw new \Exception("Failed to fetch invoices: " . $response->body());
        }

        $invoices = $response->json();
        $customerIdsByCode = Customer::withTrashed()->pluck('id', 'code');
        $rows = [];
        $count = 0;
        $now = now();

        foreach ($invoices as $data) {
            $customerId = $customerIdsByCode->get((string) $data['customer_id']);
            if (!$customerId) {
                // If customer is missing locally, we cannot assign the invoice.
                Log::warning("Skipping invoice sync for missing customer: {$data['customer_id']}");
                continue;
            }

            $periodDate = Carbon::parse($data['period']);
            $dueDate = $data['due_date'] ? Carbon::parse($data['due_date']) : $periodDate->copy()->addDays(20);

            $rows[] = [
                'customer_id' => $customerId,
                'period' => $periodDate->toDateString(),
                'legacy_id' => isset($data['id']) ? (string) $data['id'] : null,
                'uuid' => $data['uuid'] ?? (string) Str::uuid(),
                'code' => $data['code'] ?? 'INV-' . $periodDate->format('Ym') . '-' . $data['customer_id'],
                'amount' => $data['amount'],
                'status' => $data['status'],
                'due_date' => $dueDate->toDateString(),
                'generated_at' => ! empty($data['generated_at']) ? Carbon::parse($data['generated_at']) : $now,
                'last_synced_at' => ! empty($data['last_synced_at']) ? Carbon::parse($data['last_synced_at']) : $now,
                'payment_link' => $data['payment_link'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $count++;
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('invoices')->upsert($chunk, ['customer_id', 'period'], [
                'legacy_id',
                'uuid',
                'code',
                'amount',
                'status',
                'due_date',
                'generated_at',
                'last_synced_at',
                'payment_link',
                'updated_at',
            ]);
        }

        return $count;
    }

    private function syncAreasFromCustomers(): int
    {
        $response = Http::timeout(60)->get("{$this->baseUrl}/api/v1/customers");
        if (! $response->successful()) {
            throw new \Exception("Failed to fetch areas and customer fallback: " . $response->body());
        }

        return collect($response->json())
            ->map(fn (array $customer) => $this->areaResolver->resolve($customer)['area'])
            ->filter()
            ->unique()
            ->sum(function (string $name) {
                Area::updateOrCreate(
                    ['name' => $name],
                    ['code' => Str::slug($name)]
                );

                return 1;
            });
    }
}
