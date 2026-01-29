#!/usr/bin/env php
<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Router;
use App\Services\MikrotikService;

echo "=== MikroTik Profile Analysis ===\n\n";

$routers = Router::where('is_active', true)->get();

if ($routers->isEmpty()) {
    echo "❌ No active routers found.\n";
    exit(1);
}

$service = app(MikrotikService::class);
$allProfiles = [];
$routerProfiles = [];

foreach ($routers as $router) {
    echo "📡 Connecting to {$router->name} ({$router->ip_address})...\n";
    
    try {
        $service->connect($router);
        $profiles = $service->getProfiles();
        
        $profileNames = array_map(fn($p) => $p['name'], $profiles);
        sort($profileNames);
        
        $routerProfiles[$router->name] = $profileNames;
        
        foreach ($profileNames as $name) {
            if (!isset($allProfiles[$name])) {
                $allProfiles[$name] = [];
            }
            $allProfiles[$name][] = $router->name;
        }
        
        echo "   ✅ Found " . count($profiles) . " profiles\n";
        $service->disconnect();
        
    } catch (\Exception $e) {
        echo "   ❌ Failed: {$e->getMessage()}\n";
    }
    echo "\n";
}

echo "=== Analysis Results ===\n\n";

echo "1. Profile Availability Across Routers:\n";
ksort($allProfiles);
foreach ($allProfiles as $profile => $seenInRouters) {
    $count = count($seenInRouters);
    $total = $routers->count();
    $percentage = round(($count / $total) * 100);
    
    $status = ($count === $total) ? "✅ All Routers" : "⚠️  {$count}/{$total} routers";
    
    printf("%-30s %s\n", $profile, $status);
    if ($count < $total) {
        // echo "   Missing in: " . implode(', ', array_diff($routers->pluck('name')->toArray(), $seenInRouters)) . "\n";
    }
}

echo "\n2. Router Profile Manifest:\n";
foreach ($routerProfiles as $router => $profiles) {
    echo "{$router}:\n";
    echo "  " . implode(', ', $profiles) . "\n\n";
}
