<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Services\RouterSyncService;
use Illuminate\Console\Command;

class ScanRouters extends Command
{
    protected $signature = 'routers:scan {router?} {--force}';
    protected $description = 'Scan specific or all active routers for customers and map them to the database';

    public function handle()
    {
        $routerId = $this->argument('router');
        $query = Router::where('is_active', true);

        if ($routerId) {
            $query->where('id', $routerId);
        }

        $routers = $query->get();

        if ($routers->isEmpty()) {
            $this->warn('No active routers found to scan.');
            return;
        }

        $this->info("Scanning " . $routers->count() . " router(s)...");

        $syncService = app(RouterSyncService::class);

        foreach ($routers as $router) {
            $this->info("Scanning {$router->name} ({$router->ip_address})...");

            try {
                $stats = $syncService->syncCustomers($router);
                
                $this->table(
                    ['Mapped', 'Orphaned', 'Packages Updated'],
                    [[$stats['mapped'], $stats['orphaned'], $stats['synced_package']]]
                );

            } catch (\Exception $e) {
                $this->error("Scan failed for {$router->name}: " . $e->getMessage());
            }
        }

        $this->info('Scan completed.');
    }
}
