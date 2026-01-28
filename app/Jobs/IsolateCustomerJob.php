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

class IsolateCustomerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [60, 180, 600]; // 1min, 3min, 10min

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
            Log::warning("Customer {$this->customer->name} has no router assigned. Skipping isolation.");
            activity()
                ->causedBy(auth()->user() ?? null)
                ->performedOn($this->customer)
                ->withProperties(['reason' => 'no_router_assigned'])
                ->log('isolation_skipped');
            return;
        }

        $router = $this->customer->router;

        try {
            Log::info("Attempting to isolate: {$this->customer->name} ({$this->customer->pppoe_user}) on {$router->name}");

            // Connect to the customer's router
            $mikrotik->connect($router);

            // Isolate the user
            $success = $mikrotik->isolateUser($this->customer->pppoe_user);

            if ($success) {
                // Update customer status
                $this->customer->update(['status' => 'isolated']);

                // Log the action
                activity()
                    ->causedBy(auth()->user() ?? null)
                    ->performedOn($this->customer)
                    ->withProperties([
                        'router' => $router->name,
                        'pppoe_user' => $this->customer->pppoe_user,
                    ])
                    ->log('customer_isolated');

                Log::info("Successfully isolated: {$this->customer->name}");
            } else {
                Log::warning("Failed to isolate {$this->customer->name}: User not found on router");
            }

            $mikrotik->disconnect();

        } catch (Exception $e) {
            $isConfigError = str_contains($e->getMessage(), 'does not have an isolation profile configured');
            
            Log::error("Failed to isolate customer {$this->customer->name}: " . $e->getMessage());
            
            // Log the failure
            activity()
                ->causedBy(auth()->user() ?? null)
                ->performedOn($this->customer)
                ->withProperties([
                    'error' => $e->getMessage(),
                    'router' => $router->name,
                    'is_config_error' => $isConfigError
                ])
                ->log('isolation_failed');

            // Retry logic (Skip retry if it's a configuration error)
            if (!$isConfigError && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 600);
            } else {
                $this->fail($e);
            }
        }
    }
}
