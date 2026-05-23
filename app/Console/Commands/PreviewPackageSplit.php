<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Package;
use App\Models\RouterProfile;
use App\Support\NetworkProfiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PreviewPackageSplit extends Command
{
    protected $signature = 'network:preview-package-split
        {--limit=100 : Maximum suggested package rows to print}
        {--apply : Create suggested packages and reassign matching eligible customers}
        {--yes : Confirm apply mode without prompting}';

    protected $description = 'Read-only preview of router/profile/price package split candidates from active customer data';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $rows = $this->candidateRows()->take($limit);
        $reviewCount = $this->reviewRows()->count();

        $this->table(
            ['Old Package', 'Router', 'Profile', 'Price', 'Customers', 'Suggested Package Name'],
            $rows->map(fn ($row) => [
                $row->old_package_name,
                $row->router_name,
                $row->effective_profile,
                number_format((float) $row->price, 0),
                $row->customers_count,
                $this->suggestedName($row),
            ])->all()
        );

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['suggested_split_rows_total', $this->candidateRows()->count()],
                ['rows_printed', $rows->count()],
                ['excluded_review_rows', $reviewCount],
            ]
        );

        if ($reviewCount > 0) {
            $this->warn('Some customers were excluded because their effective profile is missing, isolation-like, or requires manual review.');
        }

        if ($this->option('apply')) {
            if (! $this->option('yes') && ! $this->confirm('Apply these package split suggestions now?')) {
                $this->warn('Apply cancelled.');

                return self::SUCCESS;
            }

            $this->applyCandidates($this->candidateRows());
        }

        return self::SUCCESS;
    }

    private function candidateRows()
    {
        return Customer::ebilling()
            ->join('packages', 'packages.id', '=', 'customers.package_id')
            ->join('routers', 'routers.id', '=', 'customers.router_id')
            ->select([
                'packages.id as old_package_id',
                'packages.name as old_package_name',
                'packages.price',
                'routers.id as router_id',
                'routers.name as router_name',
                DB::raw("CASE WHEN customers.status = 'isolated' AND COALESCE(customers.previous_profile, '') <> '' THEN customers.previous_profile ELSE customers.mikrotik_profile END as effective_profile"),
                DB::raw('count(*) as customers_count'),
            ])
            ->whereNotNull('customers.router_id')
            ->whereIn('customers.status', ['active', 'isolated'])
            ->groupBy('packages.id', 'packages.name', 'packages.price', 'routers.id', 'routers.name', 'effective_profile')
            ->orderByDesc('customers_count')
            ->get()
            ->filter(fn ($row) => filled($row->effective_profile) && ! NetworkProfiles::isIsolationLike($row->effective_profile))
            ->values();
    }

    private function reviewRows()
    {
        return Customer::ebilling()
            ->select('id', 'status', 'mikrotik_profile', 'previous_profile')
            ->whereIn('status', ['active', 'isolated'])
            ->get()
            ->filter(function ($customer) {
                $profile = NetworkProfiles::effectiveCustomerProfile($customer);

                return blank($profile) || NetworkProfiles::isIsolationLike($profile);
            })
            ->values();
    }

    private function suggestedName(object $row): string
    {
        return "{$row->old_package_name} - {$row->router_name} - {$row->effective_profile}";
    }

    private function applyCandidates($rows): void
    {
        $created = 0;
        $reused = 0;
        $reassigned = 0;

        DB::transaction(function () use ($rows, &$created, &$reused, &$reassigned) {
            foreach ($rows as $row) {
                $profile = RouterProfile::where('router_id', $row->router_id)
                    ->where('name', $row->effective_profile)
                    ->first();

                if (! $profile) {
                    $this->warn("Skipping {$row->router_name} / {$row->effective_profile}: profile is not in router inventory.");

                    continue;
                }

                $package = Package::firstOrCreate(
                    [
                        'router_id' => $row->router_id,
                        'mikrotik_profile' => $row->effective_profile,
                        'name' => $this->suggestedName($row),
                        'price' => $row->price,
                    ],
                    [
                        'code' => $this->uniquePackageCode($this->suggestedName($row)),
                        'rate_limit' => $profile->rate_limit,
                    ]
                );

                $package->wasRecentlyCreated ? $created++ : $reused++;

                $reassigned += Customer::ebilling()
                    ->where('package_id', $row->old_package_id)
                    ->where('router_id', $row->router_id)
                    ->whereIn('status', ['active', 'isolated'])
                    ->where(function ($query) use ($row) {
                        $query->where(function ($active) use ($row) {
                            $active->where('status', 'active')
                                ->where('mikrotik_profile', $row->effective_profile);
                        })->orWhere(function ($isolated) use ($row) {
                            $isolated->where('status', 'isolated')
                                ->where('previous_profile', $row->effective_profile);
                        });
                    })
                    ->update(['package_id' => $package->id]);
            }
        });

        $this->newLine();
        $this->table(
            ['Applied Metric', 'Count'],
            [
                ['packages_created', $created],
                ['packages_reused', $reused],
                ['customers_reassigned', $reassigned],
            ]
        );
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
