<?php

namespace App\Jobs;

use App\Models\Customer;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RouterOS\Client;
use RouterOS\Query;

class IsolateCustomerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public Customer $customer)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $host = config('services.mikrotik.host', env('MIKROTIK_HOST'));
        $user = config('services.mikrotik.user', env('MIKROTIK_USER'));
        $pass = config('services.mikrotik.pass', env('MIKROTIK_PASS'));

        if (!$host) {
            Log::warning("Mikrotik Host not configured. Skipping isolation for {$this->customer->name}.");
            return;
        }

        try {
            $client = new Client([
                'host' => $host,
                'user' => $user,
                'pass' => $pass,
                'port' => (int) env('MIKROTIK_PORT', 8728),
            ]);

            Log::info("Isolating Customer: {$this->customer->name} ({$this->customer->pppoe_user})");

            // 1. Find the PPPoE Secret ID
            $query = (new Query('/ppp/secret/print'))
                ->where('name', $this->customer->pppoe_user);
            
            $secrets = $client->query($query)->read();

            if (empty($secrets)) {
                Log::error("PPPoE User not found in Router: {$this->customer->pppoe_user}");
                return;
            }

            $secretId = $secrets[0]['.id'];

            // 2. Change Profile to 'blocked' (Assumes 'blocked' profile exists)
            // In a real app, we might check if profile exists or use an address-list strategy
            $updateQuery = (new Query('/ppp/secret/set'))
                ->equal('.id', $secretId)
                ->equal('profile', 'blocked');
            
            $client->query($updateQuery)->read();

            // 3. Kick active connection to force reconnection with new profile
            $activeQuery = (new Query('/ppp/active/print'))
                ->where('name', $this->customer->pppoe_user);
            
            $activeSessions = $client->query($activeQuery)->read();

            foreach ($activeSessions as $session) {
                $removeQuery = (new Query('/ppp/active/remove'))
                    ->equal('.id', $session['.id']);
                $client->query($removeQuery)->read();
                Log::info("Kicked active session for {$this->customer->pppoe_user}");
            }

            // 4. Update Local DB Status
            $this->customer->update(['status' => 'isolated']);

        } catch (Exception $e) {
            Log::error("Failed to isolate customer {$this->customer->name}: " . $e->getMessage());
            // Retry logic could go here
            $this->fail($e);
        }
    }
}
