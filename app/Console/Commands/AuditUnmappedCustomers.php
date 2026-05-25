<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\RouterStagedCustomer;
use App\Support\FiveMUpgrade;
use App\Support\NetworkProfiles;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class AuditUnmappedCustomers extends Command
{
    protected $signature = 'network:audit-unmapped-customers
        {--export-dir= : Optional directory where review TSV files will be written}';

    protected $description = 'Read-only audit of customers that still cannot be safely mapped to router-backed packages';

    public function handle(): int
    {
        $metrics = $this->metrics();

        $this->table(['Metric', 'Count'], $metrics);
        $this->printDetails();

        if ($exportDir = $this->option('export-dir')) {
            $this->export($exportDir);
        }

        return SymfonyCommand::SUCCESS;
    }

    private function metrics(): array
    {
        $packageWithoutRouter = Customer::ebilling()
            ->whereHas('package', fn ($query) => $query->whereNull('router_id'))
            ->count();

        $activeOrIsolatedWithoutRouter = Customer::ebilling()
            ->whereIn('status', ['active', 'isolated'])
            ->whereNull('router_id')
            ->count();

        $withPppoeNoRouter = Customer::ebilling()
            ->whereNull('router_id')
            ->whereNotNull('pppoe_user')
            ->where('pppoe_user', '!=', '')
            ->count();

        $withoutPppoe = Customer::ebilling()
            ->where(fn ($query) => $query->whereNull('pppoe_user')->orWhere('pppoe_user', ''))
            ->count();

        $withInvoiceHistory = Customer::ebilling()
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('invoices')
                ->whereColumn('invoices.customer_id', 'customers.id'))
            ->count();

        $withoutInvoiceHistory = Customer::ebilling()
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('invoices')
                ->whereColumn('invoices.customer_id', 'customers.id'))
            ->count();

        $candidates = $this->candidateRows();

        $isolatedWithoutPrevious = Customer::ebilling()
            ->where('status', 'isolated')
            ->where(fn ($query) => $query->whereNull('previous_profile')->orWhere('previous_profile', ''))
            ->count();

        $observedFiveM = Customer::ebilling()
            ->get(['id', 'mikrotik_profile'])
            ->filter(fn (Customer $customer) => FiveMUpgrade::isFiveM($customer->mikrotik_profile))
            ->count();

        return [
            ['customers_package_without_router', $packageWithoutRouter],
            ['active_or_isolated_without_router', $activeOrIsolatedWithoutRouter],
            ['customers_with_pppoe_but_no_router', $withPppoeNoRouter],
            ['customers_without_pppoe', $withoutPppoe],
            ['customers_with_invoice_history', $withInvoiceHistory],
            ['customers_without_invoice_history', $withoutInvoiceHistory],
            ['customers_matched_to_staged_by_pppoe', $candidates->count()],
            ['customers_matched_to_router_by_area_hint', 0],
            ['isolated_without_previous_profile', $isolatedWithoutPrevious],
            ['observed_5m', $observedFiveM],
        ];
    }

    private function printDetails(): void
    {
        $this->newLine();
        $this->line('Safely matchable customers by router/profile:');
        $this->table(
            ['Router', 'Profile', 'Customers'],
            $this->candidateRows()
                ->groupBy(fn ($row) => "{$row->router_name}|{$row->staged_profile}")
                ->map(function (Collection $rows) {
                    $first = $rows->first();

                    return [$first->router_name, $first->staged_profile, $rows->count()];
                })
                ->sortByDesc(fn (array $row) => $row[2])
                ->values()
                ->all()
        );

        $this->newLine();
        $this->line('Unmapped customers by status:');
        $this->table(
            ['Status', 'Customers'],
            Customer::ebilling()
                ->leftJoin('packages', 'packages.id', '=', 'customers.package_id')
                ->where(fn ($query) => $query
                    ->whereNull('customers.router_id')
                    ->orWhereNull('packages.router_id'))
                ->select('customers.status', DB::raw('count(*) as customers_count'))
                ->groupBy('customers.status')
                ->orderByDesc('customers_count')
                ->get()
                ->map(fn ($row) => [$row->status, $row->customers_count])
                ->all()
        );
    }

    private function candidateRows(): Collection
    {
        return Customer::ebilling()
            ->join('packages', 'packages.id', '=', 'customers.package_id')
            ->joinSub($this->uniqueStagedUsersQuery(), 'staged_unique', function ($join) {
                $join->on('staged_unique.pppoe_user', '=', 'customers.pppoe_user');
            })
            ->join('routers', 'routers.id', '=', 'staged_unique.router_id')
            ->join('router_profiles', function ($join) {
                $join->on('router_profiles.router_id', '=', 'staged_unique.router_id')
                    ->on('router_profiles.name', '=', 'staged_unique.profile');
            })
            ->whereIn('customers.status', ['active', 'isolated'])
            ->where(fn ($query) => $query
                ->whereNull('customers.router_id')
                ->orWhereNull('packages.router_id'))
            ->whereNotNull('customers.pppoe_user')
            ->where('customers.pppoe_user', '!=', '')
            ->where('staged_unique.disabled', false)
            ->select([
                'customers.id as customer_id',
                'customers.code as customer_code',
                'customers.name as customer_name',
                'customers.pppoe_user',
                'customers.status',
                'customers.router_id as current_router_id',
                'customers.mikrotik_profile as current_profile',
                'packages.id as old_package_id',
                'packages.name as old_package_name',
                'packages.price',
                'routers.id as router_id',
                'routers.name as router_name',
                'staged_unique.profile as staged_profile',
                'router_profiles.rate_limit',
            ])
            ->get()
            ->filter(fn ($row) => filled($row->staged_profile) && ! NetworkProfiles::isIsolationLike($row->staged_profile))
            ->values();
    }

    private function uniqueStagedUsersQuery()
    {
        return RouterStagedCustomer::query()
            ->select([
                'router_staged_customers.pppoe_user',
                DB::raw('MIN(router_staged_customers.router_id) as router_id'),
                DB::raw('MIN(router_staged_customers.profile) as profile'),
                DB::raw('MIN(router_staged_customers.disabled) as disabled'),
            ])
            ->where('router_staged_customers.status', 'unmatched')
            ->whereNotNull('router_staged_customers.pppoe_user')
            ->where('router_staged_customers.pppoe_user', '!=', '')
            ->groupBy('router_staged_customers.pppoe_user')
            ->havingRaw('COUNT(*) = 1');
    }

    private function export(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $this->writeTsv($directory.'/review-customers-matchable-by-pppoe.tsv', $this->candidateRows(), [
            'customer_id', 'customer_code', 'customer_name', 'pppoe_user', 'status', 'old_package_id',
            'old_package_name', 'price', 'router_id', 'router_name', 'staged_profile', 'rate_limit',
        ]);

        $this->writeTsv($directory.'/review-customers-package-without-router.tsv', $this->legacyPackageRows(), [
            'customer_id', 'customer_code', 'customer_name', 'pppoe_user', 'status', 'package_id',
            'package_name', 'price', 'router_id', 'mikrotik_profile',
        ]);

        $this->writeTsv($directory.'/review-active-isolated-without-router.tsv', $this->activeWithoutRouterRows(), [
            'customer_id', 'customer_code', 'customer_name', 'pppoe_user', 'status', 'package_id',
            'package_name', 'price', 'mikrotik_profile',
        ]);

        $this->writeTsv($directory.'/review-customers-no-router-no-pppoe.tsv', $this->noRouterNoPppoeRows(), [
            'customer_id', 'customer_code', 'customer_name', 'status', 'package_id', 'package_name', 'price',
        ]);

        $this->writeTsv($directory.'/review-observed-5m.tsv', $this->observedFiveMRows(), [
            'customer_id', 'customer_code', 'customer_name', 'pppoe_user', 'status', 'router_name',
            'mikrotik_profile', 'package_id', 'package_name', 'intended_profile', 'price',
        ]);

        $this->writeTsv($directory.'/review-staged-router-only.tsv', $this->stagedRows(), [
            'router_name', 'pppoe_user', 'profile', 'disabled', 'status', 'last_seen_at',
        ]);

        $this->writeTsv($directory.'/review-isolated-without-previous-profile.tsv', $this->isolatedWithoutPreviousRows(), [
            'customer_id', 'customer_code', 'customer_name', 'pppoe_user', 'router_name',
            'mikrotik_profile', 'package_id', 'package_name', 'intended_profile', 'price',
        ]);

        $this->info("Exported review TSV files to {$directory}");
    }

    private function legacyPackageRows(): Collection
    {
        return Customer::ebilling()
            ->join('packages', 'packages.id', '=', 'customers.package_id')
            ->whereNull('packages.router_id')
            ->select([
                'customers.id as customer_id',
                'customers.code as customer_code',
                'customers.name as customer_name',
                'customers.pppoe_user',
                'customers.status',
                'packages.id as package_id',
                'packages.name as package_name',
                'packages.price',
                'customers.router_id',
                'customers.mikrotik_profile',
            ])
            ->orderBy('customers.status')
            ->orderBy('customers.name')
            ->get();
    }

    private function activeWithoutRouterRows(): Collection
    {
        return Customer::ebilling()
            ->join('packages', 'packages.id', '=', 'customers.package_id')
            ->whereIn('customers.status', ['active', 'isolated'])
            ->whereNull('customers.router_id')
            ->select([
                'customers.id as customer_id',
                'customers.code as customer_code',
                'customers.name as customer_name',
                'customers.pppoe_user',
                'customers.status',
                'packages.id as package_id',
                'packages.name as package_name',
                'packages.price',
                'customers.mikrotik_profile',
            ])
            ->orderBy('customers.status')
            ->orderBy('customers.name')
            ->get();
    }

    private function noRouterNoPppoeRows(): Collection
    {
        return Customer::ebilling()
            ->join('packages', 'packages.id', '=', 'customers.package_id')
            ->whereNull('customers.router_id')
            ->where(fn ($query) => $query->whereNull('customers.pppoe_user')->orWhere('customers.pppoe_user', ''))
            ->select([
                'customers.id as customer_id',
                'customers.code as customer_code',
                'customers.name as customer_name',
                'customers.status',
                'packages.id as package_id',
                'packages.name as package_name',
                'packages.price',
            ])
            ->orderBy('customers.status')
            ->orderBy('customers.name')
            ->get();
    }

    private function observedFiveMRows(): Collection
    {
        return Customer::ebilling()
            ->leftJoin('routers', 'routers.id', '=', 'customers.router_id')
            ->leftJoin('packages', 'packages.id', '=', 'customers.package_id')
            ->select([
                'customers.id as customer_id',
                'customers.code as customer_code',
                'customers.name as customer_name',
                'customers.pppoe_user',
                'customers.status',
                'routers.name as router_name',
                'customers.mikrotik_profile',
                'packages.id as package_id',
                'packages.name as package_name',
                'packages.mikrotik_profile as intended_profile',
                'packages.price',
            ])
            ->get()
            ->filter(fn ($row) => FiveMUpgrade::isFiveM($row->mikrotik_profile))
            ->values();
    }

    private function stagedRows(): Collection
    {
        return RouterStagedCustomer::query()
            ->join('routers', 'routers.id', '=', 'router_staged_customers.router_id')
            ->where('router_staged_customers.status', 'unmatched')
            ->select([
                'routers.name as router_name',
                'router_staged_customers.pppoe_user',
                'router_staged_customers.profile',
                'router_staged_customers.disabled',
                'router_staged_customers.status',
                'router_staged_customers.last_seen_at',
            ])
            ->orderBy('routers.name')
            ->orderBy('router_staged_customers.profile')
            ->orderBy('router_staged_customers.pppoe_user')
            ->get();
    }

    private function isolatedWithoutPreviousRows(): Collection
    {
        return Customer::ebilling()
            ->leftJoin('routers', 'routers.id', '=', 'customers.router_id')
            ->leftJoin('packages', 'packages.id', '=', 'customers.package_id')
            ->where('customers.status', 'isolated')
            ->where(fn ($query) => $query->whereNull('customers.previous_profile')->orWhere('customers.previous_profile', ''))
            ->select([
                'customers.id as customer_id',
                'customers.code as customer_code',
                'customers.name as customer_name',
                'customers.pppoe_user',
                'routers.name as router_name',
                'customers.mikrotik_profile',
                'packages.id as package_id',
                'packages.name as package_name',
                'packages.mikrotik_profile as intended_profile',
                'packages.price',
            ])
            ->orderBy('routers.name')
            ->orderBy('customers.name')
            ->get();
    }

    private function writeTsv(string $path, Collection $rows, array $columns): void
    {
        $handle = fopen($path, 'wb');
        fputcsv($handle, $columns, "\t");

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($column) => $row->{$column} ?? null, $columns), "\t");
        }

        fclose($handle);
    }
}
