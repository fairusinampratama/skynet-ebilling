<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Router;
use App\Models\Customer;
use App\Models\Package;
use App\Services\MikrotikService;
use App\Services\RouterSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class PackageProfileSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_syncs_package_based_on_mikrotik_profile_mapping()
    {
        try {
            // 1. Setup Dummy Data
            // Router
            $router = Router::create([
                'name' => 'Test Router ' . uniqid(),
                'ip_address' => '10.0.0.' . rand(1, 254),
                'username' => 'admin', // Added required field
                'is_active' => true,
                'password' => 'secret', 
                'port' => 8728 // Ensure port is set just in case
            ]);
        } catch (\Exception $e) {
            $this->fail("Router creation failed: " . $e->getMessage());
        }

        // Target Package (The mapped one)
        // Name is "Marketing Name", mikrotik_profile is "Technical Name"
        $goldPackage = Package::create([
            'name' => 'Paket Sultan 100M',
            'mikrotik_profile' => 'GOLD_PROFILE', 
            'price' => 500000,
            'bandwidth_label' => '100 Mbps',
        ]);

        // Other Package (Should NOT be picked)
        $silverPackage = Package::create([
            'name' => 'Paket Rakyat', 
            'mikrotik_profile' => 'SILVER_PROFILE',
            'price' => 100000,
            'bandwidth_label' => '10 Mbps',
        ]);

        // Customer (Initially has no package or wrong package)
        $customer = Customer::create([
            'name' => 'John Doe',
            'pppoe_user' => 'john.doe',
            'status' => 'active',
            'package_id' => $silverPackage->id, // Currently on Silver
            'address' => '123 Fake St',
            'router_id' => $router->id,
        ]);

        // 2. Mock Mikrotik Service
        // We simulate the Router saying: "john.doe is using profile 'GOLD_PROFILE'"
        $mikrotikMock = Mockery::mock(MikrotikService::class);
        $mikrotikMock->shouldReceive('connect')->once();
        $mikrotikMock->shouldReceive('disconnect')->once();
        $mikrotikMock->shouldReceive('getPPPSecrets')->once()->andReturn([
            [
                'name' => 'john.doe',
                'profile' => 'GOLD_PROFILE', // <--- The match
                'service' => 'pppoe'
            ]
        ]);

        // 3. Run Sync Service
        $service = new RouterSyncService($mikrotikMock);
        $result = $service->syncCustomers($router);

        // 4. Assertions
        // Reload customer to get latest DB state
        $customer->refresh();

        $this->assertEquals(
            $goldPackage->id, 
            $customer->package_id, 
            "Customer Package ID should update to Gold Package because profile mapped to GOLD_PROFILE"
        );
        
        $this->assertNotEquals(
            $silverPackage->id,
            $customer->package_id,
            "Customer should no longer be on Silver Package"
        );

        echo "\n\n✅ TEST PASSED: 'GOLD_PROFILE' on Router mapped correctly to 'Paket Sultan 100M' in DB.\n";
    }
}
