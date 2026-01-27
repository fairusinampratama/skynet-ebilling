<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Services\MikrotikService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MonitorRouters extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'network:monitor';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor RouterOS connection and health stats (CPU, Uptime, Online Users)';

    protected $mikrotik;

    public function __construct(MikrotikService $mikrotik)
    {
        parent::__construct();
        $this->mikrotik = $mikrotik;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting network monitoring...');

        $routers = Router::all();

        foreach ($routers as $router) {
            $this->output->write("Checking {$router->name} ({$router->ip_address})... ");

            try {
                // Attempt connection
                $this->mikrotik->connect($router);

                // Fetch Stats
                $stats = $this->mikrotik->getHealthStats();

                // Update Router
                $router->update([
                    'is_active' => true,
                    'current_online_count' => $stats['online_count'],
                    'cpu_load' => $stats['cpu_load'],
                    'uptime' => $stats['uptime'],
                    'version' => $stats['version'],
                    'board_name' => $stats['board_name'],
                    'total_pppoe_count' => $stats['total_pppoe_count'],
                    'last_health_check_at' => now(),
                ]);

                $this->info("ONLINE ({$stats['online_count']} users)");
                
                // Disconnect to clean up
                $this->mikrotik->disconnect();

            } catch (\Exception $e) {
                $this->error("OFFLINE: " . $e->getMessage());
                
                // Mark as offline if connection failed
                $router->update([
                    'is_active' => false,
                    'last_health_check_at' => now(),
                ]);
            }
        }

        $this->info('Network monitoring completed.');
    }
}
