<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Router;
use App\Services\MikrotikService;

class ListRouterProfiles extends Command
{
    protected $signature = 'mikrotik:list-profiles';
    protected $description = 'List all PPP profiles from all routers';

    public function handle(MikrotikService $mikrotik)
    {
        $routers = Router::where('is_active', true)->get();

        if ($routers->isEmpty()) {
            $this->warn('No active routers found.');
            return;
        }

        foreach ($routers as $router) {
            $this->info("Connecting to Router: {$router->name} ({$router->ip_address})...");
            
            try {
                // Short timeout for listing
                $mikrotik->connect($router, ['timeout' => 5]);
                $profiles = $mikrotik->getProfiles();
                
                $this->info("Found " . count($profiles) . " profiles:");
                
                $headers = ['Name', 'Local Addr', 'Remote Addr', 'Rate Limit', 'Only One'];
                $data = [];
                
                foreach ($profiles as $profile) {
                    $data[] = [
                        $profile['name'] ?? '-',
                        $profile['local-address'] ?? '-',
                        $profile['remote-address'] ?? '-',
                        $profile['rate-limit'] ?? '-',
                        $profile['only-one'] ?? '-',
                    ];
                }
                
                $this->table($headers, $data);
                $mikrotik->disconnect();
                
            } catch (\Exception $e) {
                $this->error("Failed to fetch profiles from {$router->name}: " . $e->getMessage());
            }
            
            $this->newLine();
        }
    }
}
