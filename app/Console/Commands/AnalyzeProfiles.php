<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Router;
use App\Models\Package;
use App\Services\MikrotikService;

class AnalyzeProfiles extends Command
{
    protected $signature = 'mikrotik:analyze-profiles';
    protected $description = 'Analyze profiles across all routers vs database packages';

    public function handle(MikrotikService $mikrotik)
    {
        $routers = Router::where('is_active', true)->get();
        $dbPackages = Package::pluck('name')->toArray();
        
        $this->info("Analyzing " . $routers->count() . " Routers vs " . count($dbPackages) . " DB Packages...");
        $this->newLine();

        $allRouterProfiles = [];
        $failedRouters = [];

        // 1. Collect Data
        foreach ($routers as $router) {
            $this->output->write("Connecting to {$router->name} ({$router->ip_address})... ");
            
            try {
                $mikrotik->connect($router, ['timeout' => 3, 'attempts' => 1]);
                $profiles = $mikrotik->getProfiles();
                $mikrotik->disconnect();
                
                $profileNames = array_column($profiles, 'name');
                $allRouterProfiles[$router->name] = $profileNames;
                
                $this->info("OK (" . count($profileNames) . " profiles)");
            } catch (\Exception $e) {
                $this->error("FAILED");
                $failedRouters[] = $router->name . " (" . $e->getMessage() . ")";
            }
        }

        $this->newLine();
        $this->info("=== ANALYSIS REPORT ===");
        $this->newLine();

        // 2. Identify Missing DB Packages (Profiles exist on Router, but NOT in DB)
        $uniqueRouterProfiles = [];
        foreach ($allRouterProfiles as $profiles) {
            foreach ($profiles as $p) {
                // Ignore default/system profiles
                if (in_array($p, ['default', 'default-encryption', 'ISOLIREBILLING'])) continue;
                $uniqueRouterProfiles[] = $p;
            }
        }
        $uniqueRouterProfiles = array_unique($uniqueRouterProfiles);
        sort($uniqueRouterProfiles);

        $missingInDb = array_diff($uniqueRouterProfiles, $dbPackages);
        
        if (count($missingInDb) > 0) {
            $this->error("CRITICAL: The following Profiles exist on Routers but are MISSING in the App Database:");
            foreach ($missingInDb as $missing) {
                $this->line(" - " . $missing);
            }
            $this->warn("Action: You must create Packages with these EXACT names.");
        } else {
            $this->info("✅ All Router Profiles have corresponding Packages in the Database.");
        }

        $this->newLine();

        // 3. Identify Unused DB Packages (Exist in DB, but found on NO routers)
        $unusedInDb = array_diff($dbPackages, $uniqueRouterProfiles);
        if (count($unusedInDb) > 0) {
            $this->warn("NOTICE: The following DB Packages are not found on any connected router (might be legacy or future):");
            // Show top 10 to avoid noise
            foreach (array_slice($unusedInDb, 0, 10) as $unused) {
                $this->line(" - " . $unused);
            }
            if (count($unusedInDb) > 10) $this->line(" ... and " . (count($unusedInDb)-10) . " more.");
        }

        $this->newLine();
        
        // 4. Report Failures
        if (count($failedRouters) > 0) {
            $this->error("⚠️  Failed to connect to " . count($failedRouters) . " routers:");
            foreach ($failedRouters as $fail) {
                $this->line(" - " . $fail);
            }
        }
    }
}
