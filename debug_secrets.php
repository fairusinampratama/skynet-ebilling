<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Router;
use App\Services\MikrotikService;

$routerId = 1; // Skynet-Tutur
$router = Router::find($routerId);

if (!$router) {
    echo "Router not found.\n";
    exit(1);
}

echo "Connecting to {$router->name} ({$router->ip_address})...\n";

$mikrotik = new MikrotikService();
try {
    $mikrotik->connect($router);
    
    // Get Secrets
    $secrets = $mikrotik->getPPPSecrets();
    echo "Found " . count($secrets) . " secrets.\n";
    
    // List first 5 secrets
    $count = 0;
    foreach ($secrets as $secret) {
        if ($count >= 5) break; 
        echo "- Name: " . ($secret['name'] ?? 'N/A') . ", Profile: " . ($secret['profile'] ?? 'N/A') . "\n";
        $count++;
    }

    $mikrotik->disconnect();
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
