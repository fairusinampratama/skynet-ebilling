#!/usr/bin/env php
<?php

/**
 * MikroTik Connection Test Script
 * 
 * This script tests the MikroTik API connection and customer discovery
 * Run with: php test-mikrotik.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Router;
use App\Services\MikrotikService;

echo "=== MikroTik Connection Test ===\n\n";

// Get first active router
$router = Router::where('is_active', true)->first();

if (!$router) {
    echo "❌ No active routers found in database.\n";
    echo "Please add a router first using: php artisan tinker\n";
    exit(1);
}

echo "Testing router: {$router->name}\n";
echo "IP Address: {$router->ip_address}:{$router->port}\n";
echo "Username: {$router->username}\n\n";

$service = app(MikrotikService::class);

try {
    echo "🔌 Attempting connection...\n";
    $service->connect($router);
    echo "✅ Connected successfully!\n\n";

    // Test connection
    echo "🧪 Testing API connection...\n";
    $testResult = $service->testConnection();
    if ($testResult['success']) {
        echo "✅ API test passed: {$testResult['message']}\n\n";
    } else {
        echo "❌ API test failed: {$testResult['error']}\n\n";
        exit(1);
    }

    // Get PPPoE users
    echo "👥 Fetching PPPoE users...\n";
    $users = $service->getPPPoEUsers();
    
    echo "✅ Found " . count($users) . " PPPoE users\n\n";

    // Display first 10 users
    echo "📋 User List (first 10):\n";
    echo str_repeat('-', 80) . "\n";
    printf("%-30s %-20s %-15s %-15s\n", "Username", "IP Address", "Service", "Uptime");
    echo str_repeat('-', 80) . "\n";

    foreach (array_slice($users, 0, 10) as $user) {
        printf(
            "%-30s %-20s %-15s %-15s\n",
            $user['name'] ?? 'N/A',
            $user['address'] ?? 'N/A',
            $user['service'] ?? 'pppoe',
            $user['uptime'] ?? '0'
        );
    }

    if (count($users) > 10) {
        echo str_repeat('-', 80) . "\n";
        echo "... and " . (count($users) - 10) . " more users\n";
    }

    // Match with database
    echo "\n🔍 Matching with database customers...\n";
    $matchedCount = 0;
    $customers = \App\Models\Customer::all();

    foreach ($users as $user) {
        $username = $user['name'] ?? '';
        $customer = $customers->firstWhere('pppoe_user', $username);
        
        if ($customer) {
            $matchedCount++;
            echo "  ✓ {$username} → {$customer->code} ({$customer->name})\n";
        }
    }

    echo "\n📊 Summary:\n";
    echo "  Total PPPoE users: " . count($users) . "\n";
    echo "  Matched customers: {$matchedCount}\n";
    echo "  Unmatched: " . (count($users) - $matchedCount) . "\n";

    $service->disconnect();
    echo "\n✅ Test completed successfully!\n";

} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
