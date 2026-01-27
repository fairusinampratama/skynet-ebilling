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
     */
    public function connect(Router $router): self
    {
        $this->router = $router;

        try {
            $config = new Config([
                'host' => $router->ip_address,
                'user' => $router->username,
                'pass' => $router->password, // Auto-decrypted by Laravel's encrypted cast
                'port' => $router->port,
                'timeout' => 15,
                'attempts' => 2,
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
     * Method: Change PPPoE profile to 'blocked'
     */
    public function isolateUser(string $username): bool
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

            // Change profile to 'blocked' (you need to create this profile in MikroTik)
            $query = (new Query('/ppp/secret/set'))
                ->equal('.id', $secret['.id'])
                ->equal('profile', 'blocked');

            $this->client->query($query)->read();

            // Kick active session if any
            $this->kickUser($username);

            Log::info("Successfully isolated user: {$username} on {$this->router->name}");

            return true;
        } catch (\Exception $e) {
            Log::error("Failed to isolate user {$username} on {$this->router->name}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Reconnect a user (restore internet access)
     * Method: Change PPPoE profile back to default
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

            // Restore profile to default (or specified profile)
            $query = (new Query('/ppp/secret/set'))
                ->equal('.id', $secret['.id'])
                ->equal('profile', $profile);

            $this->client->query($query)->read();

            // Kick active session to force new profile
            $this->kickUser($username);

            Log::info("Successfully reconnected user: {$username} on {$this->router->name}");

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

            // Get Total PPPoE Secrets Count
            $secretQuery = new Query('/ppp/secret/print');
            $secrets = $this->client->query($secretQuery)->read();
            $totalPppoeCount = count($secrets);

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
