<?php

namespace App\Services;

use App\Models\Router;
use App\Models\Customer;
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
        $stats = $this->initialScanStats();

        try {
            $this->mikrotik->connect($router); // Standard timeout for heavy scan
            
            $secrets = $this->mikrotik->getPPPSecrets();
            $stats = $this->syncSecretsToEbillingCustomers($router, $secrets, $stats, $dryRun);
            
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

            // 2. eBilling-first customer scan: link existing customers only.
            $scanStats = $this->initialScanStats();
            $secrets = $this->mikrotik->getPPPSecrets();
            $scanStats = $this->syncSecretsToEbillingCustomers($router, $secrets, $scanStats, false, $activeConnections);
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

    protected function initialScanStats(): array
    {
        return [
            'total_secrets' => 0,
            'mapped' => 0,
            'not_found_ebilling' => 0,
            'unmatched_mikrotik' => 0,
            'orphaned' => 0,
            'synced_package' => 0,
            'synced_status' => 0,
            'errors' => [],
        ];
    }

    protected function syncSecretsToEbillingCustomers(
        Router $router,
        array $secrets,
        array $stats,
        bool $dryRun = false,
        array $activeConnections = []
    ): array {
        $stats['total_secrets'] = count($secrets);
        $secretUsernames = $this->secretUsernames($secrets);
        $activeUsernames = array_flip($this->secretUsernames($activeConnections));

        foreach ($secrets as $secret) {
            $pppoeUsername = $secret['name'] ?? null;
            if (!$pppoeUsername) {
                continue;
            }

            $customer = $this->findEbillingCustomerByPppoe($pppoeUsername);

            if (!$customer) {
                $stats['unmatched_mikrotik']++;
                continue;
            }

            if (!$dryRun) {
                $isOnline = array_key_exists($pppoeUsername, $activeUsernames) ? true : null;
                $this->processCustomerSync($router, $customer, $secret, $stats, $isOnline);
            }
            $stats['mapped']++;
        }

        $stats['not_found_ebilling'] = $this->markAssignedEbillingCustomersMissingFromRouter($router, $secretUsernames, $dryRun);
        $stats['orphaned'] = $stats['unmatched_mikrotik'];

        return $stats;
    }

    protected function secretUsernames(array $secrets): array
    {
        return collect($secrets)
            ->pluck('name')
            ->filter(fn ($username) => is_string($username) && $username !== '')
            ->unique()
            ->values()
            ->all();
    }

    protected function findEbillingCustomerByPppoe(string $pppoeUsername): ?Customer
    {
        return Customer::ebilling()
            ->where('pppoe_user', $pppoeUsername)
            ->get()
            ->first(fn (Customer $customer) => $customer->pppoe_user === $pppoeUsername);
    }

    protected function markAssignedEbillingCustomersMissingFromRouter(Router $router, array $secretUsernames, bool $dryRun = false): int
    {
        $query = Customer::ebilling()
            ->where('router_id', $router->id)
            ->whereNotNull('pppoe_user')
            ->where('pppoe_user', '!=', '');

        if (!empty($secretUsernames)) {
            $query->whereNotIn('pppoe_user', $secretUsernames);
        }

        $count = (clone $query)->count();

        if (! $dryRun && $count > 0) {
            $query->update([
                'mikrotik_sync_status' => 'missing',
                'mikrotik_synced_at' => null,
                'mikrotik_sync_checked_at' => now(),
            ]);
        }

        return $count;
    }

    protected function processCustomerSync(Router $router, Customer $customer, array $secret, array &$stats, ?bool $isOnline = null): void
    {
        $profileName = $secret['profile'] ?? null;
        $updates = [
            'router_id' => $router->id,
            'mikrotik_profile' => $profileName,
            'mikrotik_sync_status' => 'synced',
            'mikrotik_synced_at' => now(),
            'mikrotik_sync_checked_at' => now(),
        ];

        if ($isOnline !== null) {
            $updates['is_online'] = $isOnline;
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
