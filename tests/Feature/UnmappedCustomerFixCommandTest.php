<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\RouterProfile;
use App\Models\RouterStagedCustomer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnmappedCustomerFixCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_and_preview_are_read_only(): void
    {
        [$customer] = $this->matchableCustomer();
        $packageCount = Package::count();

        $this->artisan('network:audit-unmapped-customers')->assertExitCode(0);
        $this->artisan('network:preview-unmapped-customer-fix')->assertExitCode(0);

        $this->assertNull($customer->refresh()->router_id);
        $this->assertSame($packageCount, Package::count());
        $this->assertSame('unmatched', RouterStagedCustomer::first()->status);
    }

    public function test_apply_assigns_high_confidence_customer_to_router_profile_package(): void
    {
        [$customer, $router, $legacyPackage] = $this->matchableCustomer();

        $this->artisan('network:preview-unmapped-customer-fix --apply --yes')->assertExitCode(0);

        $customer->refresh();
        $target = Package::where('router_id', $router->id)
            ->where('mikrotik_profile', '10MB')
            ->where('price', $legacyPackage->price)
            ->firstOrFail();

        $this->assertSame($router->id, $customer->router_id);
        $this->assertSame($target->id, $customer->package_id);
        $this->assertSame('10MB', $customer->mikrotik_profile);
        $this->assertSame('synced', $customer->mikrotik_sync_status);
        $this->assertSame('matched', RouterStagedCustomer::first()->status);
        $this->assertSame($customer->id, RouterStagedCustomer::first()->matched_customer_id);
    }

    public function test_apply_skips_duplicate_staged_matches(): void
    {
        $routerA = $this->router('Router A');
        $routerB = $this->router('Router B');
        $legacyPackage = $this->legacyPackage();
        $customer = $this->customer($legacyPackage, [
            'pppoe_user' => 'duplicate.user',
            'router_id' => null,
        ]);

        $this->profile($routerA, '10MB');
        $this->profile($routerB, '10MB');
        $this->staged($routerA, 'duplicate.user', '10MB');
        $this->staged($routerB, 'duplicate.user', '10MB');

        $this->artisan('network:preview-unmapped-customer-fix --apply --yes')->assertExitCode(0);

        $this->assertNull($customer->refresh()->router_id);
        $this->assertSame($legacyPackage->id, $customer->package_id);
    }

    public function test_apply_skips_isolation_like_profile(): void
    {
        $router = $this->router('Router A');
        $legacyPackage = $this->legacyPackage();
        $customer = $this->customer($legacyPackage, [
            'pppoe_user' => 'isolated.user',
            'router_id' => null,
        ]);
        $this->staged($router, 'isolated.user', 'ISOLIREBILLING');

        $this->artisan('network:preview-unmapped-customer-fix --apply --yes')->assertExitCode(0);

        $this->assertNull($customer->refresh()->router_id);
        $this->assertSame($legacyPackage->id, $customer->package_id);
    }

    public function test_apply_skips_missing_router_profile_inventory(): void
    {
        $router = $this->router('Router A');
        $legacyPackage = $this->legacyPackage();
        $customer = $this->customer($legacyPackage, [
            'pppoe_user' => 'missing.profile',
            'router_id' => null,
        ]);
        $this->staged($router, 'missing.profile', '10MB');

        $this->artisan('network:preview-unmapped-customer-fix --apply --yes')->assertExitCode(0);

        $this->assertNull($customer->refresh()->router_id);
        $this->assertSame($legacyPackage->id, $customer->package_id);
    }

    private function matchableCustomer(): array
    {
        $router = $this->router('Router A');
        $this->profile($router, '10MB', '5M/10M');
        $legacyPackage = $this->legacyPackage();
        $customer = $this->customer($legacyPackage, [
            'pppoe_user' => 'customer.one',
            'router_id' => null,
        ]);
        $this->staged($router, 'customer.one', '10MB');

        return [$customer, $router, $legacyPackage];
    }

    private function router(string $name): Router
    {
        return Router::create([
            'name' => $name,
            'ip_address' => '10.0.0.'.random_int(1, 250),
            'username' => 'admin',
            'password' => 'secret',
            'port' => 8728,
            'is_active' => true,
        ]);
    }

    private function profile(Router $router, string $name, ?string $rateLimit = null): RouterProfile
    {
        return RouterProfile::create([
            'router_id' => $router->id,
            'name' => $name,
            'rate_limit' => $rateLimit,
        ]);
    }

    private function legacyPackage(): Package
    {
        return Package::create([
            'code' => 'LEGACY-'.uniqid(),
            'name' => 'Legacy 10M',
            'price' => 100000,
        ]);
    }

    private function customer(Package $package, array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'code' => 'CUST-'.uniqid(),
            'name' => 'Customer One',
            'phone' => '0811111111',
            'address' => 'Address',
            'pppoe_user' => 'customer.'.uniqid(),
            'package_id' => $package->id,
            'router_id' => $package->router_id,
            'mikrotik_profile' => $package->mikrotik_profile,
            'status' => 'active',
        ], $overrides));
    }

    private function staged(Router $router, string $pppoeUser, string $profile): RouterStagedCustomer
    {
        return RouterStagedCustomer::create([
            'router_id' => $router->id,
            'pppoe_user' => $pppoeUser,
            'profile' => $profile,
            'disabled' => false,
            'status' => 'unmatched',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
