<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\MikrotikService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReconnectCustomerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [60, 180, 600];

    /**
     * Create a new job instance.
     */
    public function __construct(public Customer $customer)
    {
        $this->onQueue('network-enforcement');
    }

    /**
     * Execute the job.
     */
    public function handle(MikrotikService $mikrotik): void
    {
        // Check if customer has a router assigned
        if (!$this->customer->router_id || !$this->customer->router) {
            Log::warning("Customer {$this->customer->name} has no router assigned. Skipping reconnection.");
            return;
        }

        $router = $this->customer->router;

        try {
            Log::info("Attempting to reconnect: {$this->customer->name} ({$this->customer->pppoe_user}) on {$router->name}");

            // Connect to the customer's router
            $mikrotik->connect($router);

            // Reconnect the user (restore to 'default' profile)
            $success = $mikrotik->reconnectUser($this->customer->pppoe_user, 'default');

            if ($success) {
                // Update customer status back to active
                $this->customer->update(['status' => 'active']);

                // Log the action
                activity()
                    ->causedBy(auth()->user() ?? null)
                    ->performedOn($this->customer)
                    ->withProperties([
                        'router' => $router->name,
                        'pppoe_user' => $this->customer->pppoe_user,
                    ])
                    ->log('customer_reconnected');

                Log::info("Successfully reconnected: {$this->customer->name}");
            } else {
                Log::warning("Failed to reconnect {$this->customer->name}: User not found on router");
            }

            $mikrotik->disconnect();

        } catch (Exception $e) {
            Log::error("Failed to reconnect customer {$this->customer->name}: " . $e->getMessage());
            
            // Log the failure
            activity()
                ->causedBy(auth()->user() ?? null)
                ->performedOn($this->customer)
                ->withProperties([
                    'error' => $e->getMessage(),
                    'router' => $router->name,
                ])
                ->log('reconnection_failed');

            // Retry logic
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 600);
            } else {
                $this->fail($e);
            }
        }
    }
}
