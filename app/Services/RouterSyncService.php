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
                'connection_status' => 'online',
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
             // Update health check timestamp and connection status on failure
             $router->update([
                'connection_status' => 'offline',
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
            
            // Update scan results
            if (!$dryRun) {
                $router->update([
                    'connection_status' => 'online',
                    'last_scanned_at' => now(),
                    'last_scan_customers_count' => $stats['mapped'],
                ]);
            }

            $this->mikrotik->disconnect();

        } catch (\Exception $e) {
            if (!$dryRun) {
                $router->update(['connection_status' => 'offline']);
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

            // 0.5. Sync Profiles to Database (for package creation UI)
            $this->syncProfilesToDatabase($router);

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
                    // Auto-Import Missing Customer
                    
                    // 1. Determine Package ID (Required by DB)
                    $profileName = $secret['profile'] ?? 'default';
                    $package = null;
                    
                    // Try to find matching package
                    if ($profileName) {
                        $package = Package::where('mikrotik_profile', $profileName)
                             ->where('router_id', $router->id)
                             ->first();
                             
                        if (!$package) {
                            $package = Package::where('name', $profileName)
                                ->where('router_id', $router->id)
                                ->first();
                        }
                    }
                    
                    // If no package found, Create one (Sync Detected)
                    if (!$package) {
                         $package = Package::create([
                            'name' => $profileName ?: 'Imported',
                            'router_id' => $router->id,
                            'mikrotik_profile' => $profileName,
                            'price' => 0,
                            'bandwidth_label' => 'Sync Detected',
                        ]);
                        Log::info("Auto-created package during import for {$router->name}: {$profileName}");
                    }

                    // 2. Create Customer
                    $newCustomer = Customer::create([
                        'name' => $secret['name'], // Default to username
                        'code' => 'IMP-' . strtoupper(substr(md5($secret['name'] . time()), 0, 6)), // Temp Code
                        'pppoe_user' => $pppoeUsername,
                        'pppoe_password' => $secret['password'] ?? 'imported',
                        'router_id' => $router->id,
                        'package_id' => $package->id, // Now we have a valid ID!
                        'status' => 'active', // Assume active if on router
                        'phone' => '', // Unknown
                        'address' => $secret['comment'] ?? 'Imported from Router',
                        'registered_at' => now(),
                    ]);

                    Log::info("Auto-imported customer from router {$router->name}: {$pppoeUsername}");
                    
                    // Now sync status using standard logic
                    $this->processCustomerSync($router, $newCustomer, $secret, $scanStats);
                    $scanStats['mapped']++;
                    $scanStats['orphaned']--; // It was orphaned, now adopted!
                }
            }
            $result['scan'] = $scanStats;

            // 3. Update Router Stats
            $router->update([
                'connection_status' => 'online',
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
            $router->update(['connection_status' => 'offline']);
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

    /**
     * Sync router profiles to database for UI usage
     */
    protected function syncProfilesToDatabase(Router $router): void
    {
        try {
            $profiles = $this->mikrotik->getProfiles();
            
            foreach ($profiles as $profile) {
                $name = $profile['name'] ?? '';
                
                // Skip system/isolation profiles
                if (in_array(strtolower($name), ['default', 'default-encryption'])) {
                    continue;
                }
                if (stripos($name, 'isolir') !== false || stripos($name, 'speedtest') !== false) {
                    continue;
                }

                $rateLimit = $profile['rate-limit'] ?? null;
                $bandwidth = $this->extractBandwidth($rateLimit);

                \App\Models\RouterProfile::updateOrCreate(
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
            }

            Log::info("Synced profiles to database for {$router->name}");
        } catch (\Exception $e) {
            Log::warning("Failed to sync profiles for {$router->name}: " . $e->getMessage());
        }
    }

    /**
     * Extract bandwidth from Mikrotik rate limit string
     */
    protected function extractBandwidth(?string $rateLimit): ?string
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

    protected function processCustomerSync(Router $router, Customer $customer, array $secret, array &$stats)
    {
        $updates = ['router_id' => $router->id];
        $profileName = $secret['profile'] ?? null;

        // Auto-Sync Package
        // Auto-Sync Package
        if ($profileName && $profileName !== $router->isolation_profile) {
            // Priority 1: Exact Match (Profile Key + Router ID)
            $package = Package::where('mikrotik_profile', $profileName)
                        ->where('router_id', $router->id)
                        ->first();

            // Priority 2: Name Match (Profile Name = Package Name + Router ID)
            if (!$package) {
                $package = Package::where('name', $profileName)
                            ->where('router_id', $router->id)
                            ->whereNull('mikrotik_profile')
                            ->first();

                // Priority 3: Auto-Create (Safety Net)
                // If the router has a profile "10MB" but we have no package for it, create it.
                // This ensures we never lose sync. Admin can rename/price it later.
                if (!$package) {
                    $package = Package::create([
                        'name' => $profileName,
                        'router_id' => $router->id,
                        'mikrotik_profile' => $profileName,
                        'price' => 0, // Defaults to 0, flagged for review
                        'bandwidth_label' => 'Sync Detected',
                    ]);
                    Log::info("Auto-created package for Router {$router->name}: {$profileName}");
                }
            }

            if ($package && $customer->package_id !== $package->id) {
                // Determine sync state if we want to be fancy, but simple assignment is best
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
