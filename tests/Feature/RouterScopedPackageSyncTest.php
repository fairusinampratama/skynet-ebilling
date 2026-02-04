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

class RouterScopedPackageSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prioritizes_router_scoped_package_over_global_or_other_scoped()
    {
        // 1. Setup Routers
        $routerA = Router::create([
            'name' => 'Router A ' . uniqid(),
            'ip_address' => '10.0.0.' . rand(1, 100),
            'port' => 8728,
            'username' => 'admin',
            'password' => 'secret',
            'is_active' => true,
        ]);

        $routerB = Router::create([
            'name' => 'Router B ' . uniqid(),
            'ip_address' => '10.0.0.' . rand(101, 200),
            'port' => 8728,
            'username' => 'admin',
            'password' => 'secret',
            'is_active' => true,
        ]);

        // 2. Setup Packages
        // Both Routers use technical profile "10MB"
        // Package for Router A
        $pkgScopeA = Package::create([
            'name' => 'Paket A 10M',
            'mikrotik_profile' => '10MB',
            'router_id' => $routerA->id,
            'price' => 100000,
            'bandwidth_label' => '10 Mbps',
        ]);

        // Package for Router B
        $pkgScopeB = Package::create([
            'name' => 'Paket B 10M',
            'mikrotik_profile' => '10MB',
            'router_id' => $routerB->id,
            'price' => 120000,
            'bandwidth_label' => '10 Mbps',
        ]);

        // Global Package (Scenario: Should be ignored if scoped exists)
        $pkgGlobal = Package::create([
            'name' => 'Paket Global 10M',
            'mikrotik_profile' => '10MB',
            'router_id' => null,
            'price' => 90000,
            'bandwidth_label' => '10 Mbps',
        ]);

        // 3. Setup Customer on Router A
        // Initially on Global Package (or wrong one)
        $customer = Customer::create([
            'name' => 'User on Router A',
            'phone' => '08123456789',
            'address' => 'Test Address',
            'coordinates' => '-7.123,112.123',
            'pppoe_user' => 'user.a',
            'pppoe_password' => 'secret',
            'router_id' => $routerA->id,
            'package_id' => $pkgGlobal->id, // Wrong package initially
            'status' => 'active',
            'register_date' => now(),
        ]);

        // 4. Mock Mikrotik Service for Router A
        $mikrotikMock = Mockery::mock(MikrotikService::class);
        $mikrotikMock->shouldReceive('connect')->once();
        $mikrotikMock->shouldReceive('disconnect')->once();
        $mikrotikMock->shouldReceive('getPPPSecrets')->once()->andReturn([
            [
                'name' => 'user.a',
                'profile' => '10MB', // Technical Profile
                'service' => 'pppoe'
            ]
        ]);

        // 5. Run Sync
        $service = new RouterSyncService($mikrotikMock);
        $service->syncCustomers($routerA);

        // 6. Assertions
        $customer->refresh();

        $this->assertEquals(
            $pkgScopeA->id,
            $customer->package_id,
            "Customer on Router A should be mapped to Router A specific package."
        );
        
        $this->assertNotEquals(
            $pkgScopeB->id,
            $customer->package_id,
            "Customer on Router A should NOT be mapped to Router B package."
        );

        $this->assertNotEquals(
            $pkgGlobal->id,
            $customer->package_id,
            "Customer on Router A should NOT be mapped to Global package when a specific one exists."
        );
        
        echo "\n✅ TEST PASSED: Router A customer correctly mapped to Scoped Package A.\n";
    }
}
