<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Package;
use App\Models\RouterStagedCustomer;
use App\Support\FiveMUpgrade;
use App\Support\NetworkProfiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditPackageMapping extends Command
{
    protected $signature = 'network:audit-package-mapping {--strict : Return failure when mapping issues are found}';

    protected $description = 'Read-only audit of package, router profile, customer, and staged PPPoE consistency';

    public function handle(): int
    {
        $metrics = $this->metrics();
        $issueCount = collect($metrics)
            ->filter(fn (array $row) => $row[2] === 'issue')
            ->sum(fn (array $row) => (int) $row[1]);

        $this->table(['Metric', 'Count', 'Kind'], $metrics);
        $this->printGroupedDetails();

        if ($this->option('strict') && $issueCount > 0) {
            $this->error("Package mapping audit found {$issueCount} issue(s).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function metrics(): array
    {
        $packagesWithoutRouter = Package::whereNull('router_id')->count();
        $packagesWithoutProfile = Package::whereNull('mikrotik_profile')
            ->orWhere('mikrotik_profile', '')
            ->count();
        $packagesInvalidProfile = Package::query()
            ->whereNotNull('packages.router_id')
            ->whereNotNull('packages.mikrotik_profile')
            ->where('packages.mikrotik_profile', '!=', '')
            ->leftJoin('router_profiles', function ($join) {
                $join->on('router_profiles.router_id', '=', 'packages.router_id')
                    ->on('router_profiles.name', '=', 'packages.mikrotik_profile');
            })
            ->whereNull('router_profiles.id')
            ->count();

        $customersIncomplete = Customer::ebilling()
            ->whereHas('package', fn ($query) => $query->whereNull('router_id'))
            ->count();
        $activeWithoutRouter = Customer::ebilling()
            ->whereIn('status', ['active', 'isolated'])
            ->whereNull('router_id')
            ->count();
        $routerMismatch = Customer::ebilling()
            ->join('packages', 'packages.id', '=', 'customers.package_id')
            ->whereNotNull('customers.router_id')
            ->whereNotNull('packages.router_id')
            ->whereColumn('customers.router_id', '!=', 'packages.router_id')
            ->count();
        $isolatedWithoutPrevious = Customer::ebilling()
            ->where('status', 'isolated')
            ->where(fn ($query) => $query->whereNull('previous_profile')->orWhere('previous_profile', ''))
            ->count();
        $activeIsolationLike = Customer::ebilling()
            ->where('status', 'active')
            ->get(['id', 'mikrotik_profile'])
            ->filter(fn (Customer $customer) => NetworkProfiles::isIsolationLike($customer->mikrotik_profile))
            ->count();
        $missingProfiles = $this->customersMissingProfileInventory()->count();
        $stagedUnmatched = RouterStagedCustomer::where('status', 'unmatched')->count();
        $customersIntendedFiveM = Customer::ebilling()
            ->whereHas('package', fn ($query) => $query
                ->whereNotNull('router_id')
                ->where(function ($query) {
                    $query->where('mikrotik_profile', '5')
                        ->orWhere('mikrotik_profile', 'like', '5M%')
                        ->orWhere('mikrotik_profile', 'like', '5m%');
                }))
            ->count();
        $customersObservedFiveM = Customer::ebilling()
            ->get(['id', 'mikrotik_profile'])
            ->filter(fn (Customer $customer) => FiveMUpgrade::isFiveM($customer->mikrotik_profile))
            ->count();
        $unusedSyncedFiveMProfiles = DB::table('router_profiles')
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

        return [
            ['packages_without_router', $packagesWithoutRouter, 'issue'],
            ['packages_without_mikrotik_profile', $packagesWithoutProfile, 'issue'],
            ['packages_with_invalid_router_profile', $packagesInvalidProfile, 'issue'],
            ['customers_package_without_router', $customersIncomplete, 'issue'],
            ['active_or_isolated_customers_without_router', $activeWithoutRouter, 'issue'],
            ['customers_router_package_mismatch', $routerMismatch, 'issue'],
            ['active_customers_on_isolation_like_profile', $activeIsolationLike, 'issue'],
            ['isolated_customers_without_previous_profile', $isolatedWithoutPrevious, 'review'],
            ['customer_profiles_missing_router_inventory', $missingProfiles, 'issue'],
            ['router_only_staged_unmatched', $stagedUnmatched, 'review'],
            ['customers_intended_5m', $customersIntendedFiveM, 'issue'],
            ['customers_observed_5m', $customersObservedFiveM, 'issue'],
            ['unused_synced_5m_router_profiles', $unusedSyncedFiveMProfiles, 'review'],
        ];
    }

    private function printGroupedDetails(): void
    {
        $this->newLine();
        $this->line('Active customers on isolation-like profiles:');
        $this->table(
            ['Router', 'Profile', 'Customers'],
            Customer::ebilling()
                ->join('routers', 'routers.id', '=', 'customers.router_id')
                ->where('customers.status', 'active')
                ->select('routers.name as router_name', 'customers.mikrotik_profile', DB::raw('count(*) as customers_count'))
                ->groupBy('routers.name', 'customers.mikrotik_profile')
                ->get()
                ->filter(fn ($row) => NetworkProfiles::isIsolationLike($row->mikrotik_profile))
                ->map(fn ($row) => [$row->router_name, $row->mikrotik_profile, $row->customers_count])
                ->values()
                ->all()
        );

        $this->newLine();
        $this->line('Router-only staged users by router/profile:');
        $this->table(
            ['Router', 'Profile', 'Unmatched'],
            RouterStagedCustomer::query()
                ->join('routers', 'routers.id', '=', 'router_staged_customers.router_id')
                ->where('router_staged_customers.status', 'unmatched')
                ->select('routers.name as router_name', 'router_staged_customers.profile', DB::raw('count(*) as staged_count'))
                ->groupBy('routers.name', 'router_staged_customers.profile')
                ->orderByDesc('staged_count')
                ->limit(25)
                ->get()
                ->map(fn ($row) => [$row->router_name, $row->profile ?: 'NO_PROFILE', $row->staged_count])
                ->all()
        );

        $missingProfiles = $this->customersMissingProfileInventory()
            ->map(fn ($row) => [$row->router_name, $row->effective_profile, $row->customers_count])
            ->all();

        if (! empty($missingProfiles)) {
            $this->newLine();
            $this->line('Observed profiles missing from router inventory:');
            $this->table(['Router', 'Profile', 'Customers'], $missingProfiles);
        }
    }

    private function customersMissingProfileInventory()
    {
        return Customer::ebilling()
            ->join('routers', 'routers.id', '=', 'customers.router_id')
            ->select([
                'customers.router_id',
                'routers.name as router_name',
                DB::raw("CASE WHEN customers.status = 'isolated' AND COALESCE(customers.previous_profile, '') <> '' THEN customers.previous_profile ELSE customers.mikrotik_profile END as effective_profile"),
                DB::raw('count(*) as customers_count'),
            ])
            ->whereNotNull('customers.router_id')
            ->whereRaw("COALESCE(CASE WHEN customers.status = 'isolated' AND COALESCE(customers.previous_profile, '') <> '' THEN customers.previous_profile ELSE customers.mikrotik_profile END, '') <> ''")
            ->groupBy('routers.name', 'customers.router_id', 'effective_profile')
            ->get()
            ->filter(function ($row) {
                if (NetworkProfiles::isIsolationLike($row->effective_profile)) {
                    return false;
                }

                return ! DB::table('router_profiles')
                    ->where('router_id', $row->router_id)
                    ->where('name', $row->effective_profile)
                    ->exists();
            })
            ->values();
    }
}
