<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Router;
use App\Models\RouterProfile;
use App\Services\MikrotikService;

class SyncRouterProfiles extends Command
{
    protected $signature = 'routers:sync-profiles {--router= : Specific router ID to sync}';
    protected $description = 'Sync PPP Profiles from Mikrotik routers to database';

    public function handle(): int
    {
        $routerId = $this->option('router');
        
        if ($routerId) {
            $routers = Router::where('id', $routerId)->get();
            if ($routers->isEmpty()) {
                $this->error("Router ID {$routerId} not found");
                return 1;
            }
        } else {
            $routers = Router::all();
        }

        $this->info('Starting profile sync...');
        $totalSynced = 0;

        foreach ($routers as $router) {
            $this->line("Processing: {$router->name}");
            
            try {
                $service = new MikrotikService();
                $service->connect($router);
                
                $profiles = $service->getClient()->query('/ppp/profile/print')->read();
                $service->disconnect();

                $synced = 0;
                foreach ($profiles as $profile) {
                    $name = $profile['name'] ?? '';
                    
                    // Skip system profiles
                    if (in_array(strtolower($name), ['default', 'default-encryption'])) {
                        continue;
                    }
                    
                    // Skip isolation profiles
                    if (stripos($name, 'isolir') !== false || stripos($name, 'speedtest') !== false) {
                        continue;
                    }

                    $rateLimit = $profile['rate-limit'] ?? null;
                    $bandwidth = $this->extractBandwidth($rateLimit);

                    RouterProfile::updateOrCreate(
                        [
                            'router_id' => $router->id,
                            'name' => $name,
                        ],
                        [
                            'rate_limit' => $rateLimit,
                            'bandwidth' => $bandwidth,
                            'local_address' => $profile['local-address'] ?? null,
                            'remote_address' => $profile['remote-address'] ?? null,
                            'only_one' => $profile['only-one'] ?? null,
                        ]
                    );

                    $synced++;
                }

                $this->info("  ✓ Synced {$synced} profiles for {$router->name}");
                $totalSynced += $synced;

            } catch (\Exception $e) {
                $this->error("  ✗ Failed: {$e->getMessage()}");
            }
        }

        $this->info("\nTotal profiles synced: {$totalSynced}");
        return 0;
    }

    private function extractBandwidth(?string $rateLimit): ?string
    {
        if (!$rateLimit) return null;
        
        // Parse: "2560k/15M 5120k/20M ..." → Extract "20M"
        $parts = explode(' ', $rateLimit);
        if (count($parts) >= 2) {
            $maxSpeed = $parts[1]; // e.g., "5120k/20M"
            $segments = explode('/', $maxSpeed);
            if (count($segments) >= 2) {
                return $segments[1]; // "20M"
            }
        }
        
        return null;
    }
}
