<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Router;
use App\Services\MikrotikService;
use App\Support\FiveMUpgrade;
use App\Support\NetworkProfiles;
use Illuminate\Console\Command;

class ApplyFiveMRouterProfiles extends Command
{
    protected $signature = 'network:apply-5m-router-profiles
        {--router= : Router ID or exact router name to limit the operation}
        {--apply : Update live MikroTik PPP secret profiles}
        {--yes : Confirm apply mode without prompting}';

    protected $description = 'Preview or apply live MikroTik PPP secret profile changes from 5M/5 to intended 10M/10MB profiles.';

    public function handle(): int
    {
        $customers = $this->customers(false);
        $this->printPreview($customers);

        if (! $this->option('apply')) {
            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm('Apply live MikroTik profile changes now?')) {
            $this->warn('Apply cancelled.');

            return self::SUCCESS;
        }

        $this->apply($this->customers(true));

        return self::SUCCESS;
    }

    private function customers(bool $forApply)
    {
        $routerFilter = $this->option('router');

        $customers = Customer::ebilling()
            ->with(['router', 'package'])
            ->whereNotNull('router_id')
            ->whereHas('package', fn ($query) => $query
                ->whereNotNull('router_id')
                ->whereIn('mikrotik_profile', ['10MB', '10M']))
            ->when($routerFilter, function ($query) use ($routerFilter) {
                $query->whereHas('router', function ($router) use ($routerFilter) {
                    $router->where('id', $routerFilter)
                        ->orWhere('name', $routerFilter);
                });
            })
            ->get();

        if (! $forApply) {
            $customers = $customers->filter(fn (Customer $customer) => FiveMUpgrade::isFiveM($customer->mikrotik_profile));
        }

        return $customers->values();
    }

    private function printPreview($customers): void
    {
        $this->table(
            ['Router', 'Customer ID', 'PPPoE', 'Status', 'Observed', 'Target'],
            $customers->map(fn (Customer $customer) => [
                $customer->router?->name ?? 'NO_ROUTER',
                $customer->id,
                $customer->pppoe_user,
                $customer->status,
                $customer->mikrotik_profile,
                $customer->package?->mikrotik_profile,
            ])->all()
        );

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['stored_5m_customers_with_10m_intent', $customers->count()],
            ['routers_impacted', $customers->pluck('router_id')->unique()->count()],
        ]);

        $this->line('Apply mode scans all customers with 10M/10MB intent and only updates live PPP secrets currently on 5M/5.');
    }

    private function apply($customers): void
    {
        $updated = 0;
        $missing = 0;
        $skippedIsolation = 0;
        $skippedNotFiveM = 0;
        $failed = 0;

        foreach ($customers->groupBy('router_id') as $routerId => $group) {
            $router = Router::find($routerId);

            if (! $router) {
                $failed += $group->count();

                continue;
            }

            try {
                $service = app(MikrotikService::class)->connect($router, ['timeout' => 10, 'attempts' => 1]);

                foreach ($group as $customer) {
                    try {
                        $secret = $service->getPPPSecret($customer->pppoe_user);

                        if (! $secret) {
                            $missing++;
                            $this->warn("Missing PPP secret: {$router->name} / {$customer->pppoe_user}");

                            continue;
                        }

                        $currentProfile = $secret['profile'] ?? '';

                        if (NetworkProfiles::isIsolationLike($currentProfile)) {
                            $skippedIsolation++;
                            $this->warn("Skipping isolated PPP secret: {$router->name} / {$customer->pppoe_user} ({$currentProfile})");

                            continue;
                        }

                        if (! FiveMUpgrade::isFiveM($currentProfile)) {
                            $skippedNotFiveM++;
                            $this->warn("Skipping non-5M PPP secret: {$router->name} / {$customer->pppoe_user} ({$currentProfile})");

                            continue;
                        }

                        $result = $service->updatePPPSecretProfile($customer->pppoe_user, $customer->package->mikrotik_profile);

                        $customer->update([
                            'mikrotik_profile' => $result['new_profile'],
                            'mikrotik_sync_status' => 'synced',
                            'mikrotik_synced_at' => now(),
                            'mikrotik_sync_checked_at' => now(),
                        ]);

                        $updated++;
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->error("Failed {$router->name} / {$customer->pppoe_user}: {$e->getMessage()}");
                    }
                }

                if (method_exists($service, 'disconnect')) {
                    $service->disconnect();
                }
            } catch (\Throwable $e) {
                $failed += $group->count();
                $this->error("Router {$router->name} failed: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->table(['Applied Metric', 'Count'], [
            ['updated', $updated],
            ['missing_ppp_secret', $missing],
            ['skipped_isolation_like', $skippedIsolation],
            ['skipped_not_5m_live', $skippedNotFiveM],
            ['failed', $failed],
        ]);
    }
}
