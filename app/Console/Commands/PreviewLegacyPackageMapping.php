<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Package;
use App\Models\RouterProfile;
use App\Support\FiveMUpgrade;
use App\Support\NetworkProfiles;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PreviewLegacyPackageMapping extends Command
{
    protected $signature = 'network:preview-legacy-package-mapping
        {--limit=100 : Maximum suggested mapping rows to print}
        {--apply : Create/reuse router-profile packages and reassign safe matching customers}
        {--yes : Confirm apply mode without prompting}';

    protected $description = 'Preview or apply package-first mapping for legacy blank-profile packages.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $plans = $this->plans();
        $safe = $plans->where('status', 'auto')->values();
        $review = $plans->where('status', 'review')->values();

        $this->table(
            ['Old Package', 'Router', 'Target', 'Price', 'Customers', 'Reason', 'Suggested Package'],
            $safe->take($limit)->map(fn (array $plan) => [
                $plan['old_package_name'],
                $plan['router_name'],
                $plan['target_profile']?->name ?? 'MISSING',
                number_format((float) $plan['price'], 0),
                $plan['customers_count'],
                $plan['reason'],
                $plan['target_name'],
            ])->all()
        );

        if ($review->isNotEmpty()) {
            $this->newLine();
            $this->warn('Review-only rows:');
            $this->table(
                ['Old Package', 'Router', 'Suggested', 'Price', 'Customers', 'Reason'],
                $review->take($limit)->map(fn (array $plan) => [
                    $plan['old_package_name'],
                    $plan['router_name'] ?? 'NO_ROUTER',
                    $plan['suggested_profile'] ?? 'MISSING',
                    number_format((float) $plan['price'], 0),
                    $plan['customers_count'],
                    $plan['reason'],
                ])->all()
            );
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['mapping_rows_total', $plans->count()],
            ['auto_rows', $safe->count()],
            ['review_rows', $review->count()],
            ['auto_customers', $safe->sum('customers_count')],
            ['review_customers', $review->sum('customers_count')],
            ['rows_printed', min($limit, $safe->count())],
            ['pricing_delta', 0],
        ]);

        if (! $this->option('apply')) {
            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm('Apply safe legacy package mappings now?')) {
            $this->warn('Apply cancelled.');

            return self::SUCCESS;
        }

        $this->applyPlans($safe);

        return self::SUCCESS;
    }

    private function plans(): Collection
    {
        return $this->legacyRows()
            ->map(fn (object $row) => $this->planForRow($row))
            ->values();
    }

    private function legacyRows(): Collection
    {
        return Customer::ebilling()
            ->join('packages', 'packages.id', '=', 'customers.package_id')
            ->leftJoin('routers', 'routers.id', '=', 'customers.router_id')
            ->select([
                'packages.id as old_package_id',
                'packages.name as old_package_name',
                'packages.price',
                'customers.router_id',
                DB::raw("COALESCE(routers.name, 'NO_ROUTER') as router_name"),
                DB::raw('count(*) as customers_count'),
            ])
            ->whereNotNull('customers.router_id')
            ->whereIn('customers.status', ['active', 'isolated'])
            ->where(function ($query) {
                $query->whereNull('packages.mikrotik_profile')
                    ->orWhere('packages.mikrotik_profile', '');
            })
            ->groupBy('packages.id', 'packages.name', 'packages.price', 'customers.router_id', 'router_name')
            ->orderByDesc('customers_count')
            ->get();
    }

    private function planForRow(object $row): array
    {
        [$suggested, $reason] = $this->suggestProfile((string) $row->old_package_name);
        $target = $suggested ? $this->targetProfile((int) $row->router_id, $suggested) : null;

        if (! $suggested) {
            return $this->reviewPlan($row, null, 'unable_to_infer_profile');
        }

        if (! $target) {
            return $this->reviewPlan($row, $suggested, "missing_router_profile:{$suggested}");
        }

        if (NetworkProfiles::isIsolationLike($target->name) || strcasecmp($target->name, '10MB_R') === 0) {
            return $this->reviewPlan($row, $target->name, 'unsafe_target_profile');
        }

        $targetName = $this->targetPackageName($row, $target->name);

        return [
            'status' => 'auto',
            'old_package_id' => (int) $row->old_package_id,
            'old_package_name' => (string) $row->old_package_name,
            'router_id' => (int) $row->router_id,
            'router_name' => (string) $row->router_name,
            'price' => $row->price,
            'customers_count' => (int) $row->customers_count,
            'suggested_profile' => $suggested,
            'target_profile' => $target,
            'target_name' => $targetName,
            'reason' => $reason,
        ];
    }

    private function reviewPlan(object $row, ?string $suggested, string $reason): array
    {
        return [
            'status' => 'review',
            'old_package_id' => (int) $row->old_package_id,
            'old_package_name' => (string) $row->old_package_name,
            'router_id' => $row->router_id ? (int) $row->router_id : null,
            'router_name' => (string) $row->router_name,
            'price' => $row->price,
            'customers_count' => (int) $row->customers_count,
            'suggested_profile' => $suggested,
            'target_profile' => null,
            'target_name' => null,
            'reason' => $reason,
        ];
    }

    private function suggestProfile(string $packageName): array
    {
        $name = strtolower($packageName);

        foreach ([50, 30, 25, 20, 15] as $speed) {
            if (preg_match('/(^|[^0-9])'.$speed.'\s*(m|mb|mbps)([^0-9]|$)/i', $packageName)) {
                return [$speed.'MB', 'name_'.$speed.'m'];
            }
        }

        if (preg_match('/(^|[^0-9])10\s*(m|mb|mbps)([^0-9]|$)/i', $packageName)) {
            return ['10MB', 'name_10m'];
        }

        if (preg_match('/(^|[^0-9])5\s*(m|mb|mbps)([^0-9]|$)/i', $packageName)
            || str_contains($name, 'up to 5')
            || str_contains($name, 'upto 5')
            || str_contains($name, 'up-to 5')
            || str_contains($name, 'legacy')
            || str_contains($name, 'global')
            || str_contains($name, 'promo')
            || str_contains($name, 'free')) {
            return ['10MB', 'default_legacy_to_10mb'];
        }

        return ['10MB', 'default_vague_to_10mb'];
    }

    private function targetProfile(int $routerId, string $suggested): ?RouterProfile
    {
        foreach ($this->profileCandidates($suggested) as $candidate) {
            $profile = RouterProfile::query()
                ->where('router_id', $routerId)
                ->where('name', $candidate)
                ->first();

            if ($profile) {
                return $profile;
            }
        }

        return null;
    }

    private function profileCandidates(string $suggested): array
    {
        return match (strtoupper($suggested)) {
            '10MB' => ['10MB', '10M', '10M-25M'],
            '15MB' => ['15MB', '15M'],
            '20MB' => ['20MB', '20M', '25MB'],
            '25MB' => ['25MB', '25M'],
            '30MB' => ['30MB', '30M'],
            '50MB' => ['50MB', '50M'],
            default => [$suggested],
        };
    }

    private function targetPackageName(object $row, string $targetProfile): string
    {
        return "{$row->old_package_name} - {$row->router_name} - {$targetProfile}";
    }

    private function applyPlans(Collection $plans): void
    {
        $created = 0;
        $reused = 0;
        $reassigned = 0;

        DB::transaction(function () use ($plans, &$created, &$reused, &$reassigned) {
            foreach ($plans as $plan) {
                /** @var RouterProfile $targetProfile */
                $targetProfile = $plan['target_profile'];

                $package = Package::firstOrCreate(
                    [
                        'router_id' => $plan['router_id'],
                        'mikrotik_profile' => $targetProfile->name,
                        'name' => $plan['target_name'],
                        'price' => $plan['price'],
                    ],
                    [
                        'code' => FiveMUpgrade::uniquePackageCode($plan['target_name']),
                        'rate_limit' => $targetProfile->rate_limit,
                    ]
                );

                $package->wasRecentlyCreated ? $created++ : $reused++;

                $reassigned += Customer::ebilling()
                    ->where('package_id', $plan['old_package_id'])
                    ->where('router_id', $plan['router_id'])
                    ->whereIn('status', ['active', 'isolated'])
                    ->update(['package_id' => $package->id]);
            }
        });

        $this->newLine();
        $this->table(['Applied Metric', 'Count'], [
            ['packages_created', $created],
            ['packages_reused', $reused],
            ['customers_reassigned', $reassigned],
            ['pricing_delta', 0],
        ]);
    }
}
