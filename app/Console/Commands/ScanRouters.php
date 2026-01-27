<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Models\Customer;
use App\Services\MikrotikService;
use Illuminate\Console\Command;

class ScanRouters extends Command
{
    protected $signature = 'network:scan 
                            {--router= : Specific router ID to scan}
                            {--dry-run : Run without saving changes}';

    protected $description = 'Scan all MikroTik routers and map customers to their routers';

    protected MikrotikService $mikrotik;

    public function __construct(MikrotikService $mikrotik)
    {
        parent::__construct();
        $this->mikrotik = $mikrotik;
    }

    public function handle()
    {
        $this->info('Starting router network scan...');
        $this->newLine();

        // Determine which routers to scan
        $routers = $this->option('router')
            ? Router::where('id', $this->option('router'))->get()
            : Router::where('is_active', true)->get();

        if ($routers->isEmpty()) {
            $this->error('No routers found to scan.');
            return 1;
        }

        $totalCustomersFound = 0;
        $routersScanned = 0;
        $routersFailed = 0;

        $progressBar = $this->output->createProgressBar($routers->count());
        $progressBar->start();

        foreach ($routers as $router) {
            try {
                $this->info("\nScanning: {$router->name} ({$router->ip_address})");

                // Connect to router
                $this->mikrotik->connect($router);

                // Get PPP secrets
                $secrets = $this->mikrotik->getPPPSecrets();
                $this->info("  → Found " . count($secrets) . " PPP secrets");

                $matchedCount = 0;

                // Match with customers
                foreach ($secrets as $secret) {
                    $pppoeUsername = $secret['name'] ?? null;

                    if (!$pppoeUsername) {
                        continue;
                    }

                    // Find customer by PPPoE username
                    $customer = Customer::where('pppoe_user', $pppoeUsername)->first();

                    if ($customer) {
                        if (!$this->option('dry-run')) {
                            // Update customer's router_id
                            $customer->update(['router_id' => $router->id]);
                        }
                        $matchedCount++;
                    }
                }

                $this->info("  ✓ Matched {$matchedCount} customers");
                $totalCustomersFound += $matchedCount;
                $routersScanned++;

                // Update scan timestamp and stats
                if (!$this->option('dry-run')) {
                    $router->update([
                        'last_scanned_at' => now(),
                        'last_scan_customers_count' => $matchedCount,
                        'is_active' => true, // Mark as active on successful scan
                    ]);
                }

                $this->mikrotik->disconnect();

            } catch (\Exception $e) {
                $this->error("\n  ✗ Failed to scan {$router->name}: {$e->getMessage()}");
                $routersFailed++;

                // Mark router as inactive if connection failed
                if (!$this->option('dry-run')) {
                    $router->update(['is_active' => false]);
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Summary
        $this->info('Scan complete!');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Routers Scanned', $routersScanned],
                ['Routers Failed', $routersFailed],
                ['Customers Mapped', $totalCustomersFound],
            ]
        );

        if ($this->option('dry-run')) {
            $this->warn('Dry-run mode: No changes were saved to database.');
        }

        return $routersFailed > 0 ? 1 : 0;
    }
}
