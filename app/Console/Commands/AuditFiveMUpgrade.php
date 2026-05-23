<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\RouterProfile;
use App\Support\FiveMUpgrade;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditFiveMUpgrade extends Command
{
    protected $signature = 'network:audit-5m-upgrade';

    protected $description = 'Read-only audit and preview for migrating 5M intended/live service profiles to 10M/10MB.';

    public function handle(): int
    {
        $this->printMetrics();
        $this->printPackagePlan();
        $this->printCustomerBreakdown();
        $this->printObservedProfiles();
        $this->printTargetProfiles();

        return self::SUCCESS;
    }

    private function printMetrics(): void
    {
        $fiveMPackages = Package::query()
            ->whereNotNull('router_id')
            ->get()
            ->filter(fn (Package $package) => FiveMUpgrade::isFiveM($package->mikrotik_profile));

        $intendedCustomers = Customer::ebilling()
            ->whereHas('package', fn ($query) => $query
                ->whereNotNull('router_id')
                ->where(function ($query) {
                    $query->where('mikrotik_profile', '5')
                        ->orWhere('mikrotik_profile', 'like', '5M%')
                        ->orWhere('mikrotik_profile', 'like', '5m%');
                }))
            ->count();

        $observedCustomers = Customer::ebilling()
            ->get(['id', 'mikrotik_profile'])
            ->filter(fn (Customer $customer) => FiveMUpgrade::isFiveM($customer->mikrotik_profile))
            ->count();

        $unusedFiveMProfiles = DB::table('router_profiles')
            ->where(function ($query) {
                $query->where('name', '5')
                    ->orWhere('name', 'like', '5M%')
                    ->orWhere('name', 'like', '5m%');
            })
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('packages')
                    ->join('customers', 'customers.package_id', '=', 'packages.id')
                    ->whereColumn('packages.router_id', 'router_profiles.router_id')
                    ->whereColumn('packages.mikrotik_profile', 'router_profiles.name')
                    ->whereNull('customers.deleted_at')
                    ->where(function ($query) {
                        $query->whereNull('customers.code')
                            ->orWhere('customers.code', 'not like', 'IMP-%');
                    });
            })
            ->count();

        $priceDelta = $fiveMPackages->sum(function (Package $package) {
            return $package->customers()
                ->ebilling()
                ->count() * 0;
        });

        $this->table(['Metric', 'Count'], [
            ['router_backed_5m_packages', $fiveMPackages->count()],
            ['customers_intended_5m', $intendedCustomers],
            ['customers_observed_5m', $observedCustomers],
            ['unused_synced_5m_router_profiles', $unusedFiveMProfiles],
            ['pricing_delta_after_option_a', $priceDelta],
        ]);
    }

    private function printPackagePlan(): void
    {
        $rows = Package::query()
            ->with(['router', 'customers'])
            ->whereNotNull('router_id')
            ->orderBy('router_id')
            ->orderBy('name')
            ->get()
            ->filter(fn (Package $package) => FiveMUpgrade::isFiveM($package->mikrotik_profile))
            ->map(function (Package $package) {
                $targetProfile = FiveMUpgrade::targetProfileForRouter((int) $package->router_id);
                $targetName = $targetProfile ? FiveMUpgrade::targetPackageName($package, $targetProfile->name) : null;
                $targetPackage = $targetProfile ? Package::query()
                    ->where('router_id', $package->router_id)
                    ->where('mikrotik_profile', $targetProfile->name)
                    ->where('price', $package->price)
                    ->where('name', $targetName)
                    ->first() : null;

                return [
                    $package->router?->name ?? 'NO_ROUTER',
                    $package->id,
                    $package->name,
                    $package->mikrotik_profile,
                    number_format((float) $package->price, 0),
                    $package->customers()->ebilling()->count(),
                    $targetProfile?->name ?? 'MISSING',
                    $targetPackage ? 'reuse '.$targetPackage->id : ($targetProfile ? 'create' : 'blocked'),
                    $targetName ?? '-',
                ];
            })
            ->values()
            ->all();

        $this->newLine();
        $this->line('5M package migration plan:');
        $this->table(['Router', 'Package ID', 'Package', 'From', 'Price', 'Customers', 'Target', 'Action', 'Target Package'], $rows);
    }

    private function printCustomerBreakdown(): void
    {
        $rows = Customer::ebilling()
            ->join('packages', 'packages.id', '=', 'customers.package_id')
            ->leftJoin('routers', 'routers.id', '=', 'customers.router_id')
            ->leftJoin('areas', 'areas.id', '=', 'customers.area_id')
            ->whereNotNull('packages.router_id')
            ->where(function ($query) {
                $query->where('packages.mikrotik_profile', '5')
                    ->orWhere('packages.mikrotik_profile', 'like', '5M%')
                    ->orWhere('packages.mikrotik_profile', 'like', '5m%');
            })
            ->select([
                DB::raw("COALESCE(routers.name, 'NO_ROUTER') as router_name"),
                DB::raw("COALESCE(areas.name, 'NO_AREA') as area_name"),
                'customers.status',
                DB::raw('count(*) as customers_count'),
            ])
            ->groupBy('router_name', 'area_name', 'customers.status')
            ->orderByDesc('customers_count')
            ->get()
            ->map(fn ($row) => [$row->router_name, $row->area_name, $row->status, $row->customers_count])
            ->all();

        $this->newLine();
        $this->line('Customers assigned to 5M intended packages:');
        $this->table(['Router', 'Area', 'Status', 'Customers'], $rows);
    }

    private function printObservedProfiles(): void
    {
        $rows = Customer::ebilling()
            ->leftJoin('routers', 'routers.id', '=', 'customers.router_id')
            ->select([
                DB::raw("COALESCE(routers.name, 'NO_ROUTER') as router_name"),
                'customers.status',
                'customers.mikrotik_profile',
                DB::raw('count(*) as customers_count'),
            ])
            ->where(function ($query) {
                $query->where('customers.mikrotik_profile', '5')
                    ->orWhere('customers.mikrotik_profile', 'like', '5M%')
                    ->orWhere('customers.mikrotik_profile', 'like', '5m%');
            })
            ->groupBy('router_name', 'customers.status', 'customers.mikrotik_profile')
            ->orderByDesc('customers_count')
            ->get()
            ->map(fn ($row) => [$row->router_name, $row->status, $row->mikrotik_profile, $row->customers_count])
            ->all();

        $this->newLine();
        $this->line('Customers observed on 5M-like profiles:');
        $this->table(['Router', 'Status', 'Observed Profile', 'Customers'], $rows);
    }

    private function printTargetProfiles(): void
    {
        $rows = Router::query()
            ->whereHas('profiles', fn ($query) => $query
                ->where('name', '5')
                ->orWhere('name', 'like', '5M%')
                ->orWhere('name', 'like', '5m%'))
            ->with('profiles')
            ->orderBy('name')
            ->get()
            ->map(function (Router $router) {
                $target = FiveMUpgrade::targetProfileForRouter($router->id);

                return [
                    $router->name,
                    $target?->name ?? 'MISSING',
                    $target?->rate_limit ?? '-',
                ];
            })
            ->all();

        $this->newLine();
        $this->line('Target profile availability:');
        $this->table(['Router', 'Target Profile', 'Target Rate Limit'], $rows);
    }
}
