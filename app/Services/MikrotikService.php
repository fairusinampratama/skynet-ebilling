<?php

namespace App\Services;

use App\Models\Router;
use App\Models\Customer;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Query;
use Illuminate\Support\Facades\Log;

class MikrotikService
{
    protected ?Client $client = null;
    protected ?Router $router = null;

    /**
     * Connect to a MikroTik router
     * 
     * @param Router $router
     * @param array $options Optional override for connection settings (timeout, attempts)
     */
    public function connect(Router $router, array $options = []): self
    {
        $this->router = $router;

        try {
            $timeout = $options['timeout'] ?? 10;
            // Force PHP socket timeout to respect our setting (fix for hanging connections)
            ini_set('default_socket_timeout', $timeout);

            $config = new Config([
                'host' => $router->ip_address,
                'user' => $router->username,
                'pass' => $router->password, // Auto-decrypted by Laravel's encrypted cast
                'port' => $router->port,
                'timeout' => $timeout, // Connection timeout
                'socket_timeout' => $timeout, // Read/write timeout (THIS WAS THE MISSING PIECE!)
                'attempts' => $options['attempts'] ?? 3,
            ]);

            $this->client = new Client($config);

            Log::info("Successfully connected to router: {$router->name}");
        } catch (\Exception $e) {
            Log::error("Failed to connect to router {$router->name}: {$e->getMessage()}");
            throw $e;
        }

        return $this;
    }

    public function isolateCustomerNow(Customer $customer, int $timeout = 10): void
    {
        if (!$customer->router || !$customer->pppoe_user) {
            throw new \InvalidArgumentException('Customer must have a router and PPPoE username.');
        }

        try {
            $this->connect($customer->router, ['timeout' => $timeout, 'attempts' => 1]);

            if (!$this->isolateUser($customer->pppoe_user)) {
                throw new \RuntimeException("PPPoE user '{$customer->pppoe_user}' not found on router '{$customer->router->name}'");
            }

            $customer->update([
                'status' => 'isolated',
                'mikrotik_profile' => $this->isolationProfileName(),
                'mikrotik_sync_status' => 'synced',
                'mikrotik_synced_at' => now(),
                'mikrotik_sync_checked_at' => now(),
            ]);

            activity()
                ->causedBy(auth()->user() ?? null)
                ->performedOn($customer)
                ->withProperties([
                    'router' => $customer->router->name,
                    'pppoe_user' => $customer->pppoe_user,
                    'mode' => 'realtime',
                ])
                ->log('customer_isolated');
        } finally {
            $this->disconnect();
        }
    }

    public function reconnectCustomerNow(Customer $customer, int $timeout = 10): void
    {
        if (!$customer->router || !$customer->pppoe_user) {
            throw new \InvalidArgumentException('Customer must have a router and PPPoE username.');
        }

        try {
            $this->connect($customer->router, ['timeout' => $timeout, 'attempts' => 1]);

            $fallbackProfile = $customer->package?->mikrotik_profile
                ?: $customer->mikrotik_profile
                ?: 'default';
            $restoredProfile = $this->reconnectProfileName($customer, $fallbackProfile);

            if (!$this->reconnectUser($customer->pppoe_user, $fallbackProfile)) {
                throw new \RuntimeException("PPPoE user '{$customer->pppoe_user}' not found on router '{$customer->router->name}'");
            }

            $customer->update([
                'status' => 'active',
                'mikrotik_profile' => $restoredProfile,
                'mikrotik_sync_status' => 'synced',
                'mikrotik_synced_at' => now(),
                'mikrotik_sync_checked_at' => now(),
            ]);

            activity()
                ->causedBy(auth()->user() ?? null)
                ->performedOn($customer)
                ->withProperties([
                    'router' => $customer->router->name,
                    'pppoe_user' => $customer->pppoe_user,
                    'mode' => 'realtime',
                ])
                ->log('customer_reconnected');
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Get all PPPoE secrets from router
     */
    public function getPPPSecrets(): array
    {
        $this->ensureConnected();

        try {
            $query = new Query('/ppp/secret/print');
            $response = $this->client->query($query)->read();

            Log::info("Retrieved " . count($response) . " PPP secrets from {$this->router->name}");

            return $response;
        } catch (\Exception $e) {
            Log::error("Failed to get PPP secrets from {$this->router->name}: {$e->getMessage()}");
            throw $e;
        }
    }
    
    /**
     * Get a specific PPPoE secret by username
     */
    public function getPPPSecret(string $username): ?array
    {
        $this->ensureConnected();

        try {
            return $this->findPPPSecret($username);
        } catch (\Exception $e) {
            Log::error("Failed to get PPP secret for {$username}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Get all PPP profiles from router
     */
    public function getProfiles(): array
    {
        $this->ensureConnected();

        try {
            $query = new Query('/ppp/profile/print');
            $response = $this->client->query($query)->read();

            Log::info("Retrieved " . count($response) . " PPP profiles from {$this->router->name}");

            return $response;
        } catch (\Exception $e) {
            Log::error("Failed to get PPP profiles from {$this->router->name}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Get active PPPoE connections
     */
    public function getActiveConnections(): array
    {
        $this->ensureConnected();

        try {
            $query = new Query('/ppp/active/print');
            $response = $this->client->query($query)->read();

            Log::info("Retrieved " . count($response) . " active PPP connections from {$this->router->name}");

            return $response;
        } catch (\Exception $e) {
            Log::error("Failed to get active connections from {$this->router->name}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Isolate a user (block internet access)
     * Method: Change PPPoE profile to 'isolirebilling' (case-insensitive)
     */
    public function isolateUser(string $username): bool
    {
        $this->ensureConnected();
        $isolationProfile = $this->isolationProfileName();

        try {
            // Get all available profiles to find case-insensitive match
            $matchedProfile = $this->matchProfileName($isolationProfile);
            
            if (!$matchedProfile) {
                throw new \Exception("Isolation profile '{$isolationProfile}' not found on router {$this->router->name}");
            }

            // Find the PPP secret
            $secret = $this->findPPPSecret($username);

            if (!$secret) {
                Log::warning("PPP secret not found for user: {$username} on {$this->router->name}");
                return false;
            }

            $currentProfile = $secret['profile'] ?? 'default';

            // Save previous profile if not already isolated
            if (strcasecmp($currentProfile, $isolationProfile) !== 0) {
                $customer = Customer::ebilling()->where('pppoe_user', $username)->first();
                if ($customer) {
                    $customer->update(['previous_profile' => $currentProfile]);
                }
            }

            // Change profile to isolation profile (using the exact case from router)
            $this->setPPPSecretProfile($secret, $matchedProfile);

            // Kick active session if any
            $this->kickUser($username);

            Log::info("Successfully isolated user: {$username} on {$this->router->name} (Profile: {$matchedProfile})");

            return true;
        } catch (\Exception $e) {
            Log::error("Failed to isolate user {$username} on {$this->router->name}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Reconnect a user (restore internet access)
     * Method: Change PPPoE profile back to saved previous profile
     */
    public function reconnectUser(string $username, string $profile = 'default'): bool
    {
        $this->ensureConnected();

        try {
            // Find the PPP secret
            $secret = $this->findPPPSecret($username);

            if (!$secret) {
                Log::warning("PPP secret not found for user: {$username} on {$this->router->name}");
                return false;
            }

            $customer = Customer::ebilling()->with('package')->where('pppoe_user', $username)->first();
            $targetProfile = $customer
                ? $this->reconnectProfileName($customer, $profile)
                : $profile;

            if ($customer && !empty($customer->previous_profile)) {
                Log::info("Restoring {$username} to previous profile: {$targetProfile}");
            }

            // Restore profile
            $this->setPPPSecretProfile($secret, $targetProfile);

            if ($customer && !empty($customer->previous_profile)) {
                $customer->update(['previous_profile' => null]);
            }

            // Kick active session to force new profile
            $this->kickUser($username);

            Log::info("Successfully reconnected user: {$username} on {$this->router->name} to {$targetProfile}");

            return true;
        } catch (\Exception $e) {
            Log::error("Failed to reconnect user {$username} on {$this->router->name}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Kick an active PPPoE session
     */
    public function kickUser(string $username): void
    {
        try {
            $query = (new Query('/ppp/active/print'))
                ->where('name', $username);
            
            $active = $this->client->query($query)->read();

            if (!empty($active)) {
                $session = $active[0];
                
                $query = (new Query('/ppp/active/remove'))
                    ->equal('.id', $session['.id']);

                $this->client->query($query)->read();

                Log::info("Kicked active session for user: {$username} on {$this->router->name}");
            }
        } catch (\Exception $e) {
            Log::warning("Could not kick user {$username}: {$e->getMessage()}");
        }
    }

    protected function ensureConnected(): void
    {
        if (!$this->client) {
            throw new \Exception('Not connected to router. Call connect() first.');
        }
    }

    protected function isolationProfileName(): string
    {
        $configuredProfile = trim((string) ($this->router?->isolation_profile ?? ''));

        return $configuredProfile !== '' ? $configuredProfile : 'isolirebilling';
    }

    protected function reconnectProfileName(Customer $customer, string $fallbackProfile = 'default'): string
    {
        return $customer->previous_profile
            ?: $customer->package?->mikrotik_profile
            ?: $customer->mikrotik_profile
            ?: $fallbackProfile;
    }

    protected function matchProfileName(string $profileName): ?string
    {
        foreach ($this->getProfiles() as $profile) {
            if (isset($profile['name']) && strcasecmp($profile['name'], $profileName) === 0) {
                return $profile['name'];
            }
        }

        return null;
    }

    protected function findPPPSecret(string $username): ?array
    {
        $query = (new Query('/ppp/secret/print'))
            ->where('name', $username);

        $secrets = $this->client->query($query)->read();

        return $secrets[0] ?? null;
    }

    protected function setPPPSecretProfile(array $secret, string $profile): void
    {
        $query = (new Query('/ppp/secret/set'))
            ->equal('.id', $secret['.id'])
            ->equal('profile', $profile);

        $this->client->query($query)->read();
    }

    /**
     * Test connection to router
     */
    public function testConnection(): array
    {
        if (!$this->client) {
            throw new \Exception('Not connected to router. Call connect() first.');
        }

        try {
            $query = new Query('/system/resource/print');
            $response = $this->client->query($query)->read();

            return [
                'success' => true,
                'router' => $this->router->name,
                'data' => $response[0] ?? []
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'router' => $this->router->name,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get router health statistics (CPU, Uptime, Online Count)
     */
    public function getHealthStats(): array
    {
        if (!$this->client) {
            throw new \Exception('Not connected to router. Call connect() first.');
        }

        try {
            // Get System Resources
            $resourceQuery = new Query('/system/resource/print');
            $resource = $this->client->query($resourceQuery)->read();
            $system = $resource[0] ?? [];

            // Get Online Count
            $activeQuery = new Query('/ppp/active/print');
            $active = $this->client->query($activeQuery)->read();
            $onlineCount = count($active);

            // Get Total PPPoE Secrets Count - REMOVED to prevent DoS (Update via network:monitor is too frequent)
            // This is now handled by network:scan hourly
            $totalPppoeCount = null;

            return [
                'cpu_load' => isset($system['cpu-load']) ? (int)$system['cpu-load'] : null,
                'uptime' => $system['uptime'] ?? null,
                'version' => $system['version'] ?? null,
                'board_name' => $system['board-name'] ?? null,
                'free_memory' => isset($system['free-memory']) ? (int)$system['free-memory'] : null,
                'total_memory' => isset($system['total-memory']) ? (int)$system['total-memory'] : null,
                'online_count' => $onlineCount,
                'total_pppoe_count' => $totalPppoeCount,
            ];
        } catch (\Exception $e) {
            Log::error("Failed to get health stats for {$this->router->name}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Sync Customer 'is_online' status based on active connections
     */
    public function syncCustomerOnlineStatus(array $activeConnections): void
    {
        if (!$this->router) {
            return;
        }

        $activeUsernames = array_column($activeConnections, 'name');

        if (!empty($activeUsernames)) {
            // 1. Set is_online = true for active users
            \App\Models\Customer::where('router_id', $this->router->id)
                ->ebilling()
                ->whereIn('pppoe_user', $activeUsernames)
                ->update(['is_online' => true]);

            // 2. Set is_online = false for inactive users
            \App\Models\Customer::where('router_id', $this->router->id)
                ->ebilling()
                ->whereNotIn('pppoe_user', $activeUsernames)
                ->update(['is_online' => false]);
        } else {
            // No active users -> Set all on this router to offline
            \App\Models\Customer::where('router_id', $this->router->id)
                ->ebilling()
                ->update(['is_online' => false]);
        }
        
        Log::info("Synced online status for Router: {$this->router->name} (" . count($activeUsernames) . " active)");
    }

    /**
     * Get the RouterOS client instance
     */
    public function getClient(): ?Client
    {
        return $this->client;
    }

    /**
     * Disconnect from router
     */
    public function disconnect(): void
    {
        if ($this->client) {
            $this->client = null;
            Log::info("Disconnected from router: {$this->router->name}");
        }
    }
}
