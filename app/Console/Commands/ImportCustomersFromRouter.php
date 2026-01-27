<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Models\Customer;
use App\Models\Package;
use App\Services\MikrotikService;
use Illuminate\Console\Command;

class ImportCustomersFromRouter extends Command
{
    protected $signature = 'customers:import-from-router {router_id}';
    protected $description = 'Import customers from MikroTik router PPPoE secrets';

    public function handle()
    {
        $routerId = $this->argument('router_id');
        $router = Router::find($routerId);

        if (!$router) {
            $this->error("Router with ID {$routerId} not found.");
            return 1;
        }

        $this->info("Importing customers from router: {$router->name}");
        $this->info("IP: {$router->ip_address}:{$router->port}");
        $this->newLine();

        $service = app(MikrotikService::class);

        try {
            // Connect to router
            $this->info('🔌 Connecting to router...');
            $service->connect($router);
            $this->info('✅ Connected successfully!');
            $this->newLine();

            // Get PPPoE secrets
            $this->info('👥 Fetching PPPoE secrets...');
            $secrets = $service->getPPPSecrets();
            $this->info("✅ Found " . count($secrets) . " PPPoE users");
            $this->newLine();

            // Get default package (or create one)
            $defaultPackage = Package::first();
            if (!$defaultPackage) {
                $this->warn('No packages found. Creating default package...');
                $defaultPackage = Package::create([
                    'name' => 'Default Package',
                    'price' => 100000,
                    'bandwidth_label' => '10 Mbps',
                ]);
            }

            // Import customers
            $imported = 0;
            $skipped = 0;
            $errors = 0;

            $progressBar = $this->output->createProgressBar(count($secrets));
            $progressBar->start();

            foreach ($secrets as $secret) {
                $username = $secret['name'] ?? null;

                if (!$username) {
                    $errors++;
                    $progressBar->advance();
                    continue;
                }

                // Check if customer already exists
                $existing = Customer::where('pppoe_user', $username)->first();

                if ($existing) {
                    // Update router_id if not set
                    if (!$existing->router_id) {
                        $existing->update(['router_id' => $router->id]);
                        $imported++;
                    } else {
                        $skipped++;
                    }
                } else {
                    // Create new customer
                    try {
                        Customer::create([
                            'name' => ucfirst(str_replace(['_', '-'], ' ', $username)),
                            'code' => 'IMP-' . strtoupper(substr(md5($username), 0, 6)),
                            'internal_id' => 'R' . $router->id . '-' . $imported,
                            'pppoe_user' => $username,
                            'pppoe_pass' => 'imported', // Default password
                            'address' => 'Imported from router',
                            'phone' => '-',
                            'nik' => '-',
                            'package_id' => $defaultPackage->id,
                            'router_id' => $router->id,
                            'status' => 'active',
                            'geo_lat' => null,
                            'geo_long' => null,
                        ]);
                        $imported++;
                    } catch (\Exception $e) {
                        $errors++;
                    }
                }

                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine(2);

            // Summary
            $this->info('✅ Import complete!');
            $this->newLine();
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Total PPPoE Users', count($secrets)],
                    ['New Customers Created', $imported],
                    ['Existing Customers', $skipped],
                    ['Errors', $errors],
                ]
            );

            $service->disconnect();
            $this->newLine();
            $this->info("🎉 Successfully imported customers from {$router->name}");
            $this->info("👉 View them at: http://localhost:8000/routers/{$router->id}");

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return 1;
        }
    }
}
