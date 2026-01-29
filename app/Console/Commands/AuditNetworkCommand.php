<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Models\Customer;
use App\Services\MikrotikService;
use Illuminate\Console\Command;

class AuditNetworkCommand extends Command
{
    protected $signature = 'network:audit';

    protected $description = 'Audit the synchronization status between Database and Mikrotik Routers';

    protected MikrotikService $mikrotik;

    public function __construct(MikrotikService $mikrotik)
    {
        parent::__construct();
        $this->mikrotik = $mikrotik;
    }

    public function handle()
    {
        $this->info('Starting network audit...');
        
        $routers = Router::where('is_active', true)->get();
        if ($routers->isEmpty()) {
            $this->error('No active routers found.');
            return 1;
        }

        // 1. Load all DB Customers
        $dbCustomers = Customer::all();
        $totalDbCustomers = $dbCustomers->count();
        $this->info("Total Customers in Database: {$totalDbCustomers}");
        
        // Map PPPoE User -> Customer Object for quick lookup
        $dbCustomerMap = $dbCustomers->keyBy('pppoe_user');
        
        // Trackers
        $auditStats = [];
        $allFoundPpoeUsers = [];

        foreach ($routers as $router) {
            $this->info("Auditing Router: {$router->name}...");
            try {
                $this->mikrotik->connect($router);
                $secrets = $this->mikrotik->getPPPSecrets();
                $this->mikrotik->disconnect();

                $secretNames = array_column($secrets, 'name');
                $countOnRouter = count($secretNames);
                
                // Matched: Exists in both DB and Router
                $matched = 0;
                // Orphans: Exists on Router but NOT in DB
                $orphans = 0;

                foreach ($secretNames as $name) {
                    if ($dbCustomerMap->has($name)) {
                        $matched++;
                        $allFoundPpoeUsers[] = $name;
                    } else {
                        $orphans++;
                    }
                }

                // Missing on Router: Expected (assigned to this router in DB) but not found
                // Only relevant if customer is actually assigned to this router
                $assignedToRouter = $dbCustomers->where('router_id', $router->id)->count();
                // Of those assigned, how many were found? 
                // We'll calculate "Missing" globally later to avoid confusion with multi-router assignments
                
                $auditStats[] = [
                    'router' => $router->name,
                    'total_secrets' => $countOnRouter,
                    'matched_db' => $matched,
                    'orphans_router' => $orphans,
                ];

            } catch (\Exception $e) {
                $this->error("Failed to audit {$router->name}: {$e->getMessage()}");
                $auditStats[] = [
                    'router' => $router->name . ' (FAILED)',
                    'total_secrets' => '-',
                    'matched_db' => '-',
                    'orphans_router' => '-',
                ];
            }
        }

        // Global Analysis
        $foundCount = count(array_unique($allFoundPpoeUsers));
        $missingInAction = $totalDbCustomers - $foundCount;

        $this->newLine();
        $this->table(
            ['Router', 'Secrets on Router', 'Matched in DB', 'Orphans (In Router, Not DB)'],
            $auditStats
        );

        $this->newLine();
        $this->info('--- Global Summary ---');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Customers in DB', $totalDbCustomers],
                ['Found on active Routers', $foundCount],
                ['Missing from Routers', $missingInAction],
            ]
        );
        
        // Detailed breakdown of missing if small number?
        // For now, just the counts as requested.

        return 0;
    }
}
