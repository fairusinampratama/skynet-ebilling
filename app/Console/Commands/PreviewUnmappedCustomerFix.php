<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Package;
use App\Models\RouterStagedCustomer;
use App\Support\NetworkProfiles;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class PreviewUnmappedCustomerFix extends Command
{
    protected $signature = 'network:preview-unmapped-customer-fix
        {--limit=100 : Maximum customer rows to print}
        {--apply : Assign high-confidence customers to inferred router/profile packages}
        {--yes : Confirm apply mode without prompting}';

    protected $description = 'Preview or apply high-confidence fixes for unmapped customers matched by staged PPPoE username';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $candidates = $this->candidateRows();
        $printed = $candidates->take($limit);

        $this->table(
            ['Customer', 'PPPoE', 'Status', 'Old Package', 'Router', 'Profile', 'Price', 'Target Package', 'Reason'],
            $printed->map(fn ($row) => [
                $row->customer_name,
                $row->pppoe_user,
                $row->status,
                $row->old_package_name,
                $row->router_name,
                $row->staged_profile,
                number_format((float) $row->price, 0),
                $this->targetPackageName($row),
                'unique staged PPPoE match',
            ])->all()
        );

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['candidate_customers_total', $candidates->count()],
                ['rows_printed', $printed->count()],
                ['skipped_no_unique_staged_match', $this->skippedNoUniqueStagedMatchCount()],
                ['skipped_disabled_staged_secret', $this->skippedDisabledCount()],
                ['skipped_isolation_like_profile', $this->skippedIsolationProfileCount()],
                ['skipped_missing_router_profile', $this->skippedMissingRouterProfileCount()],
            ]
        );

        if ($this->option('apply')) {
            if (! $this->option('yes') && ! $this->confirm('Apply these high-confidence customer fixes now?')) {
                $this->warn('Apply cancelled.');

                return SymfonyCommand::SUCCESS;
            }

            $this->applyCandidates($candidates);
        }

        return SymfonyCommand::SUCCESS;
    }

    private function candidateRows(): Collection
    {
        return $this->baseMatchedRows()
            ->join('router_profiles', function ($join) {
                $join->on('router_profiles.router_id', '=', 'staged_unique.router_id')
                    ->on('router_profiles.name', '=', 'staged_unique.profile');
            })
            ->where('staged_unique.disabled', false)
            ->select([
                'customers.id as customer_id',
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

    private function baseMatchedRows()
    {
        return Customer::ebilling()
            ->join('packages', 'packages.id', '=', 'customers.package_id')
            ->joinSub($this->uniqueStagedUsersQuery(), 'staged_unique', function ($join) {
                $join->on('staged_unique.pppoe_user', '=', 'customers.pppoe_user');
            })
            ->join('routers', 'routers.id', '=', 'staged_unique.router_id')
            ->whereIn('customers.status', ['active', 'isolated'])
            ->where(fn ($query) => $query
                ->whereNull('customers.router_id')
                ->orWhereNull('packages.router_id'))
            ->whereNotNull('customers.pppoe_user')
            ->where('customers.pppoe_user', '!=', '');
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

    private function targetPackageName(object $row): string
    {
        return "{$row->old_package_name} - {$row->router_name} - {$row->staged_profile}";
    }

    private function applyCandidates(Collection $candidates): void
    {
        $created = 0;
        $reused = 0;
        $updated = 0;

        DB::transaction(function () use ($candidates, &$created, &$reused, &$updated) {
            foreach ($candidates as $row) {
                $package = Package::firstOrCreate(
                    [
                        'router_id' => $row->router_id,
                        'mikrotik_profile' => $row->staged_profile,
                        'name' => $this->targetPackageName($row),
                        'price' => $row->price,
                    ],
                    [
                        'code' => $this->uniquePackageCode($this->targetPackageName($row)),
                        'rate_limit' => $row->rate_limit,
                    ]
                );

                $package->wasRecentlyCreated ? $created++ : $reused++;

                $updated += Customer::ebilling()
                    ->where('id', $row->customer_id)
                    ->update([
                        'router_id' => $row->router_id,
                        'package_id' => $package->id,
                        'mikrotik_profile' => $row->staged_profile,
                        'mikrotik_sync_status' => 'synced',
                        'mikrotik_synced_at' => now(),
                        'mikrotik_sync_checked_at' => now(),
                    ]);

                RouterStagedCustomer::where('router_id', $row->router_id)
                    ->where('pppoe_user', $row->pppoe_user)
                    ->update([
                        'matched_customer_id' => $row->customer_id,
                        'status' => 'matched',
                    ]);
            }
        });

        $this->newLine();
        $this->table(
            ['Applied Metric', 'Count'],
            [
                ['packages_created', $created],
                ['packages_reused', $reused],
                ['customers_updated', $updated],
            ]
        );
    }

    private function skippedNoUniqueStagedMatchCount(): int
    {
        $uniqueUsers = $this->uniqueStagedUsersQuery();

        return Customer::ebilling()
            ->join('packages', 'packages.id', '=', 'customers.package_id')
            ->leftJoinSub($uniqueUsers, 'staged_unique', function ($join) {
                $join->on('staged_unique.pppoe_user', '=', 'customers.pppoe_user');
            })
            ->whereIn('customers.status', ['active', 'isolated'])
            ->where(fn ($query) => $query
                ->whereNull('customers.router_id')
                ->orWhereNull('packages.router_id'))
            ->whereNotNull('customers.pppoe_user')
            ->where('customers.pppoe_user', '!=', '')
            ->whereNull('staged_unique.pppoe_user')
            ->count();
    }

    private function skippedDisabledCount(): int
    {
        return (clone $this->baseMatchedRows())
            ->where('staged_unique.disabled', true)
            ->count();
    }

    private function skippedIsolationProfileCount(): int
    {
        return $this->baseMatchedRows()
            ->where('staged_unique.disabled', false)
            ->select('staged_unique.profile')
            ->get()
            ->filter(fn ($row) => blank($row->profile) || NetworkProfiles::isIsolationLike($row->profile))
            ->count();
    }

    private function skippedMissingRouterProfileCount(): int
    {
        return $this->baseMatchedRows()
            ->leftJoin('router_profiles', function ($join) {
                $join->on('router_profiles.router_id', '=', 'staged_unique.router_id')
                    ->on('router_profiles.name', '=', 'staged_unique.profile');
            })
            ->where('staged_unique.disabled', false)
            ->whereNotNull('staged_unique.profile')
            ->whereNull('router_profiles.id')
            ->get(['staged_unique.profile'])
            ->filter(fn ($row) => ! NetworkProfiles::isIsolationLike($row->profile))
            ->count();
    }

    private function uniquePackageCode(string $name): string
    {
        $base = Str::upper(Str::slug($name));
        $base = $base !== '' ? $base : 'PACKAGE';
        $candidate = $base;
        $suffix = 1;

        while (Package::where('code', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }
}
