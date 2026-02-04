<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Package;
use App\Models\Router;
use Illuminate\Support\Facades\File;

class MigrateGlobalPackagesToScoped extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'packages:migrate-scoped';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate Global Packages to Router-Scoped based on JSON analysis';

    /**
     * Router Mapping based on Prefixes
     */
    protected $prefixMap = [
        'ARJ' => 'Skynet-Arjosari',
        'RDG' => 'Skynet-PPPoE Randuagung',
        'KRN' => 'Skynet-Krian',
        'KNDT' => 'Skynet-Kendit',
        'SRGD' => 'Skynet-Srigading',
        'BMY' => 'Skynet-Bumiayu-Malang',
        'STL' => 'Skynet-Sentul',
        'SLT' => 'Skynet-Sentul', // Alternative prefix
        'SDDG' => 'Skynet-Blitar',
        'SKHJ' => 'Skynet-Kasin',
        'TSM' => 'Skynet-Tasikmadu-ITN', // Guessing TSM matches Tasikmadu
        // 'PWD' => 'Skynet-Purwosari', // Not confirmed
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $jsonPath = base_path('final_customer_data.json');
        if (!File::exists($jsonPath)) {
            $this->error('final_customer_data.json not found!');
            return;
        }

        $customers = json_decode(File::get($jsonPath), true);
        $this->info("Analyzing " . count($customers) . " customer records...");

        // 1. Analyze: Which routers use which packages?
        // Map: [RouterName] => [Set of Package Names]
        $routerPackages = [];

        foreach ($customers as $data) {
            $code = $data['code'] ?? '';
            $pkgName = $data['package'] ?? '';
            
            if (!$code || !$pkgName) continue;

            $prefix = $this->getPrefix($code);
            $routerName = $this->getRouterName($prefix);

            if ($routerName) {
                if (!isset($routerPackages[$routerName])) {
                    $routerPackages[$routerName] = [];
                }
                if (!in_array($pkgName, $routerPackages[$routerName])) {
                    $routerPackages[$routerName][] = $pkgName;
                }
            }
        }

        // 2. Execute Migration
        foreach ($routerPackages as $routerName => $packages) {
            $router = Router::where('name', $routerName)->first();
            
            if (!$router) {
                $this->warn("Router not found in DB: {$routerName} (Prefix mapped properly?)");
                continue;
            }

            $this->info("Processing Router: {$router->name} (ID: {$router->id})");

            foreach ($packages as $pkgName) {
                // Find original Global Package to copy details
                // We accept matching by Exact Name first
                $globalPkg = Package::where('name', $pkgName)->whereNull('router_id')->first();

                if (!$globalPkg) {
                    $this->warn("  - Global Package '$pkgName' not found. Creating fresh from scratch if needed.");
                    // Fallback: Create generic if missing
                    $price = 0; $bw = 'Unknown';
                } else {
                    $price = $globalPkg->price;
                    $bw = $globalPkg->bandwidth_label;
                }

                // Create or Update Scoped Package
                $scopedPkg = Package::firstOrCreate(
                    [
                        'name' => $pkgName,
                        'router_id' => $router->id
                    ],
                    [
                        'price' => $price,
                        'bandwidth_label' => $bw ?? 'Unknown',
                        'mikrotik_profile' => $pkgName, // Default to name
                    ]
                );

                if ($scopedPkg->wasRecentlyCreated) {
                    $this->line("  + Created Scoped Package: {$pkgName}");
                } else {
                    $this->line("  . Exists: {$pkgName}");
                }

                // 3. Update Customers who use this package on this router
                // We go back to our raw data map or query DB?
                // Better: Query customers on this router who have this package name (via 'package' rel or raw 'package_id')
                // But wait, the customer might still point to Global Package ID.
                // Or customer might not have package_id set if imported raw.
                
                // Let's iterate the Original Data again to be precise?
                // Or just:
                // Find Customers on this Router whose CURRENT Package Name matches $pkgName
                // And update their package_id to $scopedPkg->id
                
                // Note: Customer model matching
            }
        }

        // 3. Pass 2: Assign Customers to these new Packages
        $this->info("Assigning Customers to Scoped Packages...");
        foreach ($customers as $data) {
           $code = $data['code'] ?? '';
           $pkgName = $data['package'] ?? '';
           
           if (!$code || !$pkgName) continue;

           $customer = \App\Models\Customer::where('code', $code)->first();
           if (!$customer) continue;

           // Find the scoped package for this customer's router
           if ($customer->router_id) {
               $scopedPkg = Package::where('name', $pkgName)
                   ->where('router_id', $customer->router_id)
                   ->first();

               if ($scopedPkg && $customer->package_id !== $scopedPkg->id) {
                   $customer->update(['package_id' => $scopedPkg->id]);
                   $this->comment("  Updated {$customer->name} -> {$pkgName} (Router: {$customer->router_id})");
               }
           }
        }

        $this->info("Migration Complete.");
    }

    private function getPrefix($code)
    {
        // Extract letters from start of string
        if (preg_match('/^([A-Z]+)/', $code, $matches)) {
            return $matches[1];
        }
        return '';
    }

    private function getRouterName($prefix)
    {
        return $this->prefixMap[$prefix] ?? null;
    }
}
