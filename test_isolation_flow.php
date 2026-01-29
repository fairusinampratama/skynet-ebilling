<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Customer;
use App\Models\Router;
use App\Services\MikrotikService;
use App\Jobs\IsolateCustomerJob;
use App\Jobs\ReconnectCustomerJob;
use Illuminate\Support\Facades\Log;

// --- CONFIGURATION ---
$ROUTER_NAME = 'Skynet-Krian'; // A known safe router
$TEST_CUSTOMER_NAME = 'ABDUL ROSID'; // Or leave null to pick random
// ---------------------

echo "\n🚀 STARTING END-TO-END ISOLATION TEST\n";
echo "=======================================\n";

// 1. SELECT CUSTOMER
echo "\n🔍 1. Selecting Test Customer...\n";
$query = Customer::whereHas('router', fn($q) => $q->where('name', $ROUTER_NAME))
    ->where('status', 'active');

if ($TEST_CUSTOMER_NAME) {
    $query->where('name', 'like', "%$TEST_CUSTOMER_NAME%");
}

$customer = $query->first();

if (!$customer) {
    echo "❌ ERROR: No active customer found to test on $ROUTER_NAME.\n";
    exit(1);
}

echo "   ✅ Selected: {$customer->name} (ID: {$customer->id})\n";
echo "   Address: {$customer->address}\n";
echo "   PPPoE: {$customer->pppoe_user}\n";

// 2. CHECK ROUTER CONNECTIVITY & CURRENT PROFILE
echo "\n📡 2. Checking Router Connection & Pre-State...\n";
$mikrotik = new MikrotikService();
try {
    // Fix: connect() requires the router object to be passed
    $mikrotik->connect($customer->router);
    echo "   ✅ Connected to router: {$customer->router->name}\n";
    
    // Check isolation profile config
    if (!$customer->router->isolation_profile) {
        throw new Exception("Router has no isolation_profile configured!");
    }
    echo "   ✅ Router Isolation Profile Config: {$customer->router->isolation_profile}\n";

    // Get current secret
    $secret = $mikrotik->getPPPSecret($customer->pppoe_user);
    if (!$secret) {
        throw new Exception("PPPoE Secret not found on router!");
    }
    $originalProfile = $secret['profile'];
    echo "   ✅ Current Router Profile: $originalProfile\n";

    if ($originalProfile === $customer->router->isolation_profile) {
        throw new Exception("Customer is ALREADY isolated on the router! Cannot start test.");
    }

} catch (Exception $e) {
    echo "❌ PRE-CHECK FAILED: " . $e->getMessage() . "\n";
    exit(1);
}

// 3. PERFORM ISOLATION
echo "\n🔒 3. ACTIONS: Isolating Customer...\n";
try {
    // Manually run the logic equivalent to the job/controller to ensure full sync execution for test
    $customer->update(['status' => 'isolated']);
    IsolateCustomerJob::dispatchSync($customer);
    echo "   ✅ IsolateJob executed.\n";
} catch (Exception $e) {
    echo "❌ ACTION FAILED: " . $e->getMessage() . "\n";
    // Attempt cleanup
    $customer->update(['status' => 'active']);
    exit(1);
}

// 4. VERIFY ISOLATION
echo "\n✅ 4. VERIFICATION: Checking Isolation State...\n";
// DB Check
$customer->refresh();
if ($customer->status !== 'isolated') {
    echo "   ❌ DB STATUS FAIL: Expected 'isolated', got '{$customer->status}'\n";
} else {
    echo "   ✅ DB Status: isolated\n";
}

if ($customer->previous_profile !== $originalProfile) {
    echo "   ❌ PREVIOUS PROFILE FAIL: Expected '$originalProfile', got '{$customer->previous_profile}'\n";
} else {
    echo "   ✅ DB Saved Profile: {$customer->previous_profile}\n";
}

// Router Check
try {
    $secret = $mikrotik->getPPPSecret($customer->pppoe_user);
    $currentProfile = $secret['profile'];
    
    if ($currentProfile !== $customer->router->isolation_profile) {
        echo "   ❌ ROUTER PROFILE FAIL: Expected '{$customer->router->isolation_profile}', got '$currentProfile'\n";
    } else {
        echo "   ✅ Router Profile: $currentProfile (Correctly Isolated)\n";
    }
} catch (Exception $e) {
    echo "❌ VERIFICATION ERROR: " . $e->getMessage() . "\n";
}

echo "\n⏳ Pausing 5 seconds before Reconnection...\n";
sleep(5);

// 5. PERFORM RECONNECTION
echo "\n🔓 5. ACTIONS: Reconnecting Customer...\n";
try {
    $customer->update(['status' => 'active']);
    ReconnectCustomerJob::dispatchSync($customer);
    echo "   ✅ ReconnectJob executed.\n";
} catch (Exception $e) {
    echo "❌ ACTION FAILED: " . $e->getMessage() . "\n";
    exit(1);
}

// 6. VERIFY RECONNECTION
echo "\n✅ 6. VERIFICATION: Checking Connection Restored...\n";
// DB Check
$customer->refresh();
if ($customer->status !== 'active') {
    echo "   ❌ DB STATUS FAIL: Expected 'active', got '{$customer->status}'\n";
} else {
    echo "   ✅ DB Status: active\n";
}

if ($customer->previous_profile !== null) {
    echo "   ❌ PREVIOUS PROFILE FAIL: Expected NULL, got '{$customer->previous_profile}'\n";
} else {
    echo "   ✅ DB Previous Profile cleared.\n";
}

// Router Check
try {
    $secret = $mikrotik->getPPPSecret($customer->pppoe_user);
    $currentProfile = $secret['profile'];
    
    if ($currentProfile !== $originalProfile) {
        echo "   ❌ ROUTER PROFILE FAIL: Expected '$originalProfile', got '$currentProfile'\n";
    } else {
        echo "   ✅ Router Profile: $currentProfile (Restored Successfully)\n";
    }
} catch (Exception $e) {
    echo "❌ VERIFICATION ERROR: " . $e->getMessage() . "\n";
}

echo "\n✨ TEST COMPLETE\n";
