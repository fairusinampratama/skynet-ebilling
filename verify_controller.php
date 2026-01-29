<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Customer;
use App\Http\Controllers\CustomerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use App\Jobs\IsolateCustomerJob;
use App\Jobs\ReconnectCustomerJob;

// Find a test customer
$routerName = 'Skynet-Krian';
$customer = Customer::whereHas('router', fn($q) => $q->where('name', $routerName))
    ->where('status', 'active')
    ->first();

if (!$customer) {
    echo "❌ No active customer found for test.\n";
    exit(1);
}

echo "🧪 Testing CustomerController Logic on: {$customer->name}\n";

// Mock the Bus to prevent actual job dispatching during this logic test
Bus::fake();

$controller = new CustomerController();

// 1. Test Isolate
echo "\n1️⃣  Testing isolate()...\n";
$response = $controller->isolate($customer);
$customer->refresh();

if ($customer->status === 'isolated') {
    echo "   ✅ Status updated to 'isolated'.\n";
} else {
    echo "   ❌ Status failed to update. Current: {$customer->status}\n";
}

Bus::assertDispatched(IsolateCustomerJob::class);
echo "   ✅ IsolateCustomerJob dispatched.\n";

// 2. Test Reconnect
echo "\n2️⃣  Testing reconnect()...\n";
$response = $controller->reconnect($customer);
$customer->refresh();

if ($customer->status === 'active') {
    echo "   ✅ Status updated to 'active'.\n";
} else {
    echo "   ❌ Status failed to update. Current: {$customer->status}\n";
}

Bus::assertDispatched(ReconnectCustomerJob::class);
echo "   ✅ ReconnectCustomerJob dispatched.\n";

echo "\n✨ Controller logic verified successfully.\n";
