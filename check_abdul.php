<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Customer;
use App\Services\MikrotikService;

$customer = Customer::where('name', 'ABDUL ROSID')->first();

if (!$customer) {
    die("❌ Customer 'ABDUL ROSID' not found.\n");
}

echo "🔍 Checking status for: {$customer->name}\n";
echo "   DB Status: " . $customer->status . "\n";
echo "   Router: " . $customer->router->name . "\n";
echo "   Expected Isolation Profile: " . $customer->router->isolation_profile . "\n";

try {
    $mikrotik = new MikrotikService();
    $mikrotik->connect($customer->router);
    $secret = $mikrotik->getPPPSecret($customer->pppoe_user);

    if ($secret) {
        echo "   Live Router Profile: " . ($secret['profile'] ?? 'UNKNOWN') . "\n";
        
        if ($secret['profile'] === $customer->router->isolation_profile) {
            echo "   ✅ VERIFIED: Customer is ISOLATED on Router.\n";
        } elseif ($secret['profile'] === 'default') {
            echo "   ⚠️ Customer is ACTIVE (Not Isolated) on Router.\n";
        } else {
            echo "   ⚠️ Customer is on unknown profile: {$secret['profile']}\n";
        }
    } else {
        echo "   ❌ PPPoE Secret not found on router.\n";
    }

} catch (\Exception $e) {
    echo "   ❌ Error checking router: " . $e->getMessage() . "\n";
}
