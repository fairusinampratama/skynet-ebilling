<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Package;
use App\Support\FiveMUpgrade;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpgradeFiveMPackages extends Command
{
    protected $signature = 'network:upgrade-5m-packages
        {--apply : Reassign customers from 5M packages to price-preserving 10M/10MB packages}
        {--yes : Confirm apply mode without prompting}';

    protected $description = 'Preview or apply the price-preserving eBilling package migration from 5M to 10M/10MB.';

    public function handle(): int
    {
        $plans = $this->plans();
        $this->printPlans($plans);

        if (! $this->option('apply')) {
            return self::SUCCESS;
        }

        if ($plans->contains(fn ($plan) => $plan['blocked'])) {
            $this->error('Cannot apply while one or more 5M packages have no 10MB/10M target profile.');

            return self::FAILURE;
        }

        if (! $this->option('yes') && ! $this->confirm('Apply the 5M package migration now?')) {
            $this->warn('Apply cancelled.');

            return self::SUCCESS;
        }

        $this->applyPlans($plans);

        return self::SUCCESS;
    }

    private function plans()
    {
        return Package::query()
            ->with('router')
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
                $customers = $package->customers()->ebilling()->count();

                return [
                    'source' => $package,
                    'target_profile' => $targetProfile,
                    'target_name' => $targetName,
                    'target_package' => $targetPackage,
                    'customers' => $customers,
                    'blocked' => $customers > 0 && ! $targetProfile,
                    'action' => $targetPackage ? 'reuse' : ($targetProfile ? 'create' : 'blocked'),
                ];
            })
            ->values();
    }

    private function printPlans($plans): void
    {
        $this->table(
            ['Router', 'Source ID', 'Source Package', 'Price', 'Customers', 'Target', 'Action', 'Target Package'],
            $plans->map(fn ($plan) => [
                $plan['source']->router?->name ?? 'NO_ROUTER',
                $plan['source']->id,
                $plan['source']->name,
                number_format((float) $plan['source']->price, 0),
                $plan['customers'],
                $plan['target_profile']?->name ?? 'MISSING',
                $plan['action'],
                $plan['target_package']?->id ?? ($plan['target_name'] ?? '-'),
            ])->all()
        );

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['5m_packages', $plans->count()],
            ['customers_to_reassign', $plans->sum('customers')],
            ['packages_to_create', $plans->where('action', 'create')->count()],
            ['packages_to_reuse', $plans->where('action', 'reuse')->count()],
            ['blocked_packages', $plans->where('blocked', true)->count()],
            ['pricing_delta', 0],
        ]);
    }

    private function applyPlans($plans): void
    {
        $created = 0;
        $reused = 0;
        $reassigned = 0;
        $previousProfilesUpdated = 0;

        DB::transaction(function () use ($plans, &$created, &$reused, &$reassigned, &$previousProfilesUpdated) {
            foreach ($plans as $plan) {
                /** @var Package $source */
                $source = $plan['source'];
                $targetProfile = $plan['target_profile'];

                if (! $targetProfile) {
                    continue;
                }

                $target = $plan['target_package'];

                if (! $target) {
                    $target = Package::create([
                        'code' => FiveMUpgrade::uniquePackageCode($plan['target_name']),
                        'name' => $plan['target_name'],
                        'router_id' => $source->router_id,
                        'mikrotik_profile' => $targetProfile->name,
                        'rate_limit' => $targetProfile->rate_limit,
                        'price' => $source->price,
                    ]);
                    $created++;
                } else {
                    $reused++;
                }

                $previousProfilesUpdated += Customer::ebilling()
                    ->where('package_id', $source->id)
                    ->where('status', 'isolated')
                    ->where(function ($query) {
                        $query->where('previous_profile', '5')
                            ->orWhere('previous_profile', 'like', '5M%')
                            ->orWhere('previous_profile', 'like', '5m%');
                    })
                    ->update(['previous_profile' => $targetProfile->name]);

                $reassigned += Customer::ebilling()
                    ->where('package_id', $source->id)
                    ->update(['package_id' => $target->id]);
            }
        });

        $this->newLine();
        $this->table(['Applied Metric', 'Count'], [
            ['packages_created', $created],
            ['packages_reused', $reused],
            ['customers_reassigned', $reassigned],
            ['isolated_previous_profiles_updated', $previousProfilesUpdated],
            ['pricing_delta', 0],
        ]);
    }
}
