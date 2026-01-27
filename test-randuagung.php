#!/usr/bin/env php
<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Router;
use App\Services\MikrotikService;

$router = Router::find(16); // Randuagung
$service = app(MikrotikService::class);

echo "🔌 Connecting to: {$router->name} ({$router->ip_address}:{$router->port})\n\n";

try {
    $service->connect($router);
    echo "✅ Connected!\n\n";

    // Get PPPoE secrets (not active connections)
    $secrets = $service->getPPPSecrets();
    
    echo "📋 Found " . count($secrets) . " PPPoE secrets:\n";
    echo str_repeat('-', 100) . "\n";
    printf("%-5s %-40s %-20s %-15s\n", "#", "Username", "IP/Pool", "Service");
    echo str_repeat('-', 100) . "\n";

    foreach (array_slice($secrets, 0, 20) as $i => $user) {
        printf(
            "%-5d %-40s %-20s %-15s\n",
            $i + 1,
            $user['name'] ?? 'N/A',
            $user['address'] ?? $user['local-address'] ?? 'pool',
            $user['service'] ?? 'any'
        );
    }

    if (count($secrets) > 20) {
        echo "... and " . (count($secrets) - 20) . " more\n";
    }

    // Try to match with database
    echo "\n🔍 Checking matches with database:\n";
    $customers = \App\Models\Customer::all();
    $matched = [];
    
    foreach ($secrets as $secret) {
        $username = $secret['name'] ?? '';
        $customer = $customers->firstWhere('pppoe_user', $username);
        
        if ($customer) {
            $matched[] = [
                'pppoe' => $username,
                'code' => $customer->code,
                'name' => $customer->name,
                'id' => $customer->id
            ];
        }
    }

    if (count($matched) > 0) {
        echo "✅ Found " . count($matched) . " matches:\n";
        foreach (array_slice($matched, 0, 10) as $m) {
            echo "  • {$m['pppoe']} → {$m['code']}: {$m['name']}\n";
        }
    } else {
        echo "❌ No matches found\n";
        echo "\nFirst 5 PPPoE usernames:\n";
        foreach (array_slice($secrets, 0, 5) as $s) {
            echo "  - " . ($s['name'] ?? 'N/A') . "\n";
        }
        echo "\nFirst 5 customer PPPoE usernames in database:\n";
        foreach ($customers->take(5) as $c) {
            echo "  - " . ($c->pppoe_user ?? 'NULL') . "\n";
        }
    }

    $service->disconnect();
    echo "\n✅ Test complete!\n";

} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
