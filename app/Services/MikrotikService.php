<?php

namespace App\Services;

use App\Models\Router;
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
            $timeout = $options['timeout'] ?? 3;
            // Force PHP socket timeout to respect our setting (fix for hanging connections)
            ini_set('default_socket_timeout', $timeout);

            $config = new Config([
                'host' => $router->ip_address,
                'user' => $router->username,
                'pass' => $router->password, // Auto-decrypted by Laravel's encrypted cast
                'port' => $router->port,
                'timeout' => $timeout, // Connection timeout
                'socket_timeout' => $timeout, // Read/write timeout (THIS WAS THE MISSING PIECE!)
                'attempts' => $options['attempts'] ?? 2,
            ]);

            $this->client = new Client($config);

            Log::info("Successfully connected to router: {$router->name}");
        } catch (\Exception $e) {
            Log::error("Failed to connect to router {$router->name}: {$e->getMessage()}");
            throw $e;
        }

        return $this;
    }

    /**
     * Get all PPPoE secrets from router
     */
    public function getPPPSecrets(): array
    {
        if (!$this->client) {
            throw new \Exception('Not connected to router. Call connect() first.');
        }

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
        if (!$this->client) {
            throw new \Exception('Not connected to router. Call connect() first.');
        }

        try {
            $query = (new Query('/ppp/secret/print'))
                ->where('name', $username);
            
            $secrets = $this->client->query($query)->read();

            return $secrets[0] ?? null;
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
        if (!$this->client) {
            throw new \Exception('Not connected to router. Call connect() first.');
        }

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
        if (!$this->client) {
            throw new \Exception('Not connected to router. Call connect() first.');
        }

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
     * Method: Change PPPoE profile to configured isolation profile
     */
    public function isolateUser(string $username): bool
    {
        if (!$this->client) {
            throw new \Exception('Not connected to router. Call connect() first.');
        }

        if (empty($this->router->isolation_profile)) {
            Log::warning("Isolation skipped: No isolation profile configured for router {$this->router->name}");
            throw new \Exception("Router {$this->router->name} does not have an isolation profile configured.");
        }

        try {
            // Find the PPP secret
            $query = (new Query('/ppp/secret/print'))
                ->where('name', $username);
            
            $secrets = $this->client->query($query)->read();

            if (empty($secrets)) {
                Log::warning("PPP secret not found for user: {$username} on {$this->router->name}");
                return false;
            }

            $secret = $secrets[0];
            $currentProfile = $secret['profile'] ?? 'default';

            // Don't overwrite if likely already isolated
            if ($currentProfile !== $this->router->isolation_profile) {
                // Update customer record with previous profile
                $customer = \App\Models\Customer::where('pppoe_user', $username)->first();
                if ($customer) {
                    $customer->update(['previous_profile' => $currentProfile]);
                }
            }

            // Change profile to confirmed isolation profile
            $query = (new Query('/ppp/secret/set'))
                ->equal('.id', $secret['.id'])
                ->equal('profile', $this->router->isolation_profile);

            $this->client->query($query)->read();

            // Kick active session if any
            $this->kickUser($username);

            Log::info("Successfully isolated user: {$username} on {$this->router->name} (Profile: {$this->router->isolation_profile})");

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
        if (!$this->client) {
            throw new \Exception('Not connected to router. Call connect() first.');
        }

        try {
            // Find the PPP secret
            $query = (new Query('/ppp/secret/print'))
                ->where('name', $username);
            
            $secrets = $this->client->query($query)->read();

            if (empty($secrets)) {
                Log::warning("PPP secret not found for user: {$username} on {$this->router->name}");
                return false;
            }

            $secret = $secrets[0];

            // Determine target profile
            $targetProfile = $profile; // Default fallback

            // Check database for previous profile
            $customer = \App\Models\Customer::where('pppoe_user', $username)->first();
            if ($customer && !empty($customer->previous_profile)) {
                $targetProfile = $customer->previous_profile;
                Log::info("Restoring {$username} to previous profile: {$targetProfile}");
                
                // Clear the saved profile
                $customer->update(['previous_profile' => null]);
            }

            // Restore profile
            $query = (new Query('/ppp/secret/set'))
                ->equal('.id', $secret['.id'])
                ->equal('profile', $targetProfile);

            $this->client->query($query)->read();

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
    protected function kickUser(string $username): void
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
                ->whereIn('pppoe_user', $activeUsernames)
                ->update(['is_online' => true]);

            // 2. Set is_online = false for inactive users
            \App\Models\Customer::where('router_id', $this->router->id)
                ->whereNotIn('pppoe_user', $activeUsernames)
                ->update(['is_online' => false]);
        } else {
            // No active users -> Set all on this router to offline
            \App\Models\Customer::where('router_id', $this->router->id)
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
