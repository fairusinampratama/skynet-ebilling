<?php

namespace App\Services;

use App\Models\Router;
use App\Models\Customer;
use App\Models\Package;
use Illuminate\Support\Facades\Log;

class RouterSyncService
{
    protected MikrotikService $mikrotik;

    public function __construct(MikrotikService $mikrotik)
    {
        $this->mikrotik = $mikrotik;
    }

    /**
     * Sync Router Health (Status, CPU, Active Users)
     */
    public function syncHealthStatus(Router $router): array
    {
        try {
            // Strict timeout for UI responsiveness: 5 seconds, 1 attempt
            $this->mikrotik->connect($router, ['timeout' => 5, 'attempts' => 1]);
            
            // 1. Fetch System Resources (Fast)
            $resourceQuery = new \RouterOS\Query('/system/resource/print');
            $resource = $this->mikrotik->getClient()->query($resourceQuery)->read();
            $system = $resource[0] ?? [];

            // 2. Fetch Active Connections (Heavy - do only once)
            $activeConnections = $this->mikrotik->getActiveConnections();
            $onlineCount = count($activeConnections);

            // 3. Sync Customer Status
            $this->mikrotik->syncCustomerOnlineStatus($activeConnections);

            // 4. Update Router Stats in DB
            $router->update([
                'is_active' => true,
                'current_online_count' => $onlineCount,
                'cpu_load' => isset($system['cpu-load']) ? (int)$system['cpu-load'] : null,
                'uptime' => $system['uptime'] ?? null,
                'version' => $system['version'] ?? null,
                'board_name' => $system['board-name'] ?? null,
                'last_health_check_at' => now(),
            ]);

            $this->mikrotik->disconnect();

            return [
                'success' => true,
                'online_count' => $onlineCount,
                'message' => "Connected! Synced {$onlineCount} active users."
            ];

        } catch (\Exception $e) {
             // Update offline status
             $router->update([
                'is_active' => false,
                'last_health_check_at' => now(),
           ]);

           return [
               'success' => false,
               'error' => $e->getMessage(),
               'message' => "Connection error: {$e->getMessage()}"
           ];
        }
    }

    /**
     * Scan and Map Customers
     */
    public function syncCustomers(Router $router, bool $dryRun = false): array
    {
        $stats = [
            'total_secrets' => 0,
            'mapped' => 0,
            'orphaned' => 0,
            'synced_package' => 0,
            'synced_status' => 0,
            'errors' => []
        ];

        try {
            $this->mikrotik->connect($router); // Standard timeout for heavy scan
            
            $secrets = $this->mikrotik->getPPPSecrets();
            $stats['total_secrets'] = count($secrets);

            foreach ($secrets as $secret) {
                $pppoeUsername = $secret['name'] ?? null;
                if (!$pppoeUsername) continue;

                $customer = Customer::where('pppoe_user', $pppoeUsername)->first();

                if ($customer) {
                    if (!$dryRun) {
                        $this->processCustomerSync($router, $customer, $secret, $stats);
                    }
                    $stats['mapped']++;
                } else {
                    $stats['orphaned']++;
                }
            }
            
            // Mark router active on success
            if (!$dryRun) {
                $router->update([
                    'last_scanned_at' => now(),
                    'last_scan_customers_count' => $stats['mapped'],
                    'is_active' => true, 
                ]);
            }

            $this->mikrotik->disconnect();

        } catch (\Exception $e) {
            if (!$dryRun) {
                $router->update(['is_active' => false]);
            }
            throw $e; // Re-throw to let caller handle critical failure
        }

        return $stats;
    }

    /**
     * Full Sync: Health + Customers + Status (One Connection)
     */

    public function fullSync(Router $router): array
    {
        try {
            // Strict timeout for UX: 2 seconds (aggressive for fast feedback)
            $this->mikrotik->connect($router, ['timeout' => 2, 'attempts' => 1]);

            $result = [
                'health' => [],
                'scan' => [],
                'success' => true
            ];

            // 0. Smart Auto-Configuration (Detection)
            if (empty($router->isolation_profile)) {
                $this->detectAndSetIsolationProfile($router);
            }

            // 1. Health Stats & Online Status
            $resourceQuery = new \RouterOS\Query('/system/resource/print');
            $resource = $this->mikrotik->getClient()->query($resourceQuery)->read();
            $system = $resource[0] ?? [];

            $activeConnections = $this->mikrotik->getActiveConnections();
            $onlineCount = count($activeConnections);
            $this->mikrotik->syncCustomerOnlineStatus($activeConnections);

            // 2. Customer Scan Logic (Reuse internal logic if possible, but for purity we inline nicely)
            $scanStats = [
                'total_secrets' => 0,
                'mapped' => 0,
                'orphaned' => 0,
                'synced_package' => 0,
                'synced_status' => 0,
            ];

            $secrets = $this->mikrotik->getPPPSecrets();
            $scanStats['total_secrets'] = count($secrets);

            foreach ($secrets as $secret) {
                $pppoeUsername = $secret['name'] ?? null;
                if (!$pppoeUsername) continue;

                $customer = Customer::where('pppoe_user', $pppoeUsername)->first();
                if ($customer) {
                    $this->processCustomerSync($router, $customer, $secret, $scanStats);
                    $scanStats['mapped']++;
                } else {
                    $scanStats['orphaned']++;
                }
            }
            $result['scan'] = $scanStats;

            // 3. Update Router Stats
            $router->update([
                'is_active' => true,
                'current_online_count' => $onlineCount,
                'cpu_load' => isset($system['cpu-load']) ? (int)$system['cpu-load'] : null,
                'uptime' => $system['uptime'] ?? null,
                'version' => $system['version'] ?? null,
                'board_name' => $system['board-name'] ?? null,
                'last_health_check_at' => now(),
                'last_scanned_at' => now(), // Also update scan timestamp
                'last_scan_customers_count' => $scanStats['mapped'],
            ]);

            $this->mikrotik->disconnect();

            return $result;

        } catch (\Exception $e) {
            $router->update(['is_active' => false]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Auto-detect and configure isolation profile if missing
     */
    protected function detectAndSetIsolationProfile(Router $router): void
    {
        try {
            $profiles = $this->mikrotik->getProfiles();
            $commonNames = ['isolirebilling', 'isolir', 'isolated', 'nonpayment', 'block', 'suspend', 'expired'];
            
            foreach ($profiles as $profile) {
                $profileName = $profile['name'] ?? '';
                if (in_array(strtolower($profileName), $commonNames)) {
                    $router->update(['isolation_profile' => $profileName]);
                    Log::info("Auto-configured isolation profile for {$router->name}: {$profileName}");
                    break;
                }
            }
        } catch (\Exception $e) {
            Log::warning("Failed to auto-detect isolation profile for {$router->name}: " . $e->getMessage());
        }
    }

    protected function processCustomerSync(Router $router, Customer $customer, array $secret, array &$stats)
    {
        $updates = ['router_id' => $router->id];
        $profileName = $secret['profile'] ?? null;

        // Auto-Sync Package
        if ($profileName && $profileName !== $router->isolation_profile) {
            $package = Package::where('name', $profileName)->first();
            if ($package && $customer->package_id !== $package->id) {
                $updates['package_id'] = $package->id;
                $stats['synced_package']++;
            }
        }

        // Auto-Sync Status (Isolation Logic)
        if ($router->isolation_profile) {
            if ($profileName === $router->isolation_profile) {
                if ($customer->status !== 'isolated') {
                    $updates['status'] = 'isolated';
                    $stats['synced_status']++;
                }
            } else {
                if ($customer->status === 'isolated') {
                    $updates['status'] = 'active';
                    $stats['synced_status']++;
                }
            }
        }

        $customer->update($updates);
    }
}
