<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Models\Customer;
use App\Models\Package;
use App\Services\MikrotikService;
use App\Services\RouterSyncService;
use Illuminate\Console\Command;

class ScanRouters extends Command
{
    protected $signature = 'network:scan 
                            {--router= : Specific router ID to scan}
                            {--dry-run : Run without saving changes}';

    protected $description = 'Scan all MikroTik routers and map customers to their routers';

    protected RouterSyncService $syncService;
    protected MikrotikService $mikrotik; // Kept for constructor compatibility if needed, but primary logic moves to syncService

    public function __construct(RouterSyncService $syncService, MikrotikService $mikrotik)
    {
        parent::__construct();
        $this->syncService = $syncService;
        $this->mikrotik = $mikrotik;
    }

    public function handle()
    {
        $this->info('Starting router network scan...');
        $this->newLine();

        // Determine which routers to scan
        $routers = $this->option('router')
            ? Router::where('id', $this->option('router'))->get()
            : Router::all(); // Scan ALL routers (including inactive to detect when they come back online)

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
                // Use the Service!
                // Note: The service doesn't return text output, so we lose the per-customer log lines in CLI
                // unless we enhance the service or just accept the summary.
                // For a robust cleaner app, summary is usually better.
                
                $stats = $this->syncService->syncCustomers($router, $this->option('dry-run'));

                $this->info("\nScanning: {$router->name} ({$router->ip_address})");
                $this->info("  ✓ Found {$stats['total_secrets']} secrets");
                $this->info("  ✓ Mapped {$stats['mapped']} customers");
                if ($stats['synced_package'] > 0) $this->info("  ✓ Updated {$stats['synced_package']} packages");
                if ($stats['synced_status'] > 0) $this->info("  ✓ Updated {$stats['synced_status']} statuses");
                
                $totalCustomersFound += $stats['mapped'];
                $routersScanned++;

            } catch (\Exception $e) {
                $this->error("\n  ✗ Failed to scan {$router->name}: {$e->getMessage()}");
                $routersFailed++;
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
