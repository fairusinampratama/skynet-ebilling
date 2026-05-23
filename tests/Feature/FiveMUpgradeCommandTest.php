<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\RouterProfile;
use App\Services\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiveMUpgradeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_and_upgrade_preview_are_read_only(): void
    {
        $router = $this->router('Router A');
        $source = $this->package($router, '5M', 'Old 5M', 100000);
        $this->profile($router, '10MB', '5M/10M');
        $customer = $this->customer($source, ['router_id' => $router->id]);

        $packageCount = Package::count();
        $customerPackage = $customer->package_id;

        $this->artisan('network:audit-5m-upgrade')->assertExitCode(0);
        $this->artisan('network:upgrade-5m-packages')->assertExitCode(0);

        $this->assertSame($packageCount, Package::count());
        $this->assertSame($customerPackage, $customer->refresh()->package_id);
    }

    public function test_upgrade_apply_creates_target_package_preserves_price_and_reassigns_customers(): void
    {
        $router = $this->router('Router A');
        $source = $this->package($router, '5M', 'Old 5M', 100000);
        $this->profile($router, '10MB', '5M/10M');
        $customer = $this->customer($source, ['router_id' => $router->id]);

        $this->artisan('network:upgrade-5m-packages --apply --yes')->assertExitCode(0);

        $target = Package::where('router_id', $router->id)
            ->where('mikrotik_profile', '10MB')
            ->where('price', 100000)
            ->firstOrFail();

        $this->assertSame('Old 5M', $target->name);
        $this->assertSame('5M/10M', $target->rate_limit);
        $this->assertSame($target->id, $customer->refresh()->package_id);
    }

    public function test_upgrade_apply_reuses_matching_target_package(): void
    {
        $router = $this->router('Router A');
        $source = $this->package($router, '5M', 'Plan - Router A - 5M', 100000);
        $target = $this->package($router, '10MB', 'Plan - Router A - 10MB', 100000);
        $customer = $this->customer($source, ['router_id' => $router->id]);

        $this->artisan('network:upgrade-5m-packages --apply --yes')->assertExitCode(0);

        $this->assertSame(2, Package::count());
        $this->assertSame($target->id, $customer->refresh()->package_id);
    }

    public function test_upgrade_apply_updates_isolated_previous_profile(): void
    {
        $router = $this->router('Router A');
        $source = $this->package($router, '5M', 'Old 5M', 100000);
        $this->profile($router, '10MB', '5M/10M');
        $customer = $this->customer($source, [
            'router_id' => $router->id,
            'status' => 'isolated',
            'mikrotik_profile' => 'ISOLIREBILLING',
            'previous_profile' => '5M',
        ]);

        $this->artisan('network:upgrade-5m-packages --apply --yes')->assertExitCode(0);

        $this->assertSame('10MB', $customer->refresh()->previous_profile);
    }

    public function test_upgrade_apply_fails_when_router_has_no_normal_target_profile(): void
    {
        $router = $this->router('Router A');
        $source = $this->package($router, '5M', 'Old 5M', 100000);
        $this->profile($router, '10MB_R', '5M/10M');
        $customer = $this->customer($source, ['router_id' => $router->id]);

        $this->artisan('network:upgrade-5m-packages --apply --yes')->assertExitCode(1);

        $this->assertSame($source->id, $customer->refresh()->package_id);
    }

    public function test_live_router_preview_does_not_write(): void
    {
        $router = $this->router('Router A');
        $target = $this->package($router, '10MB', 'Plan 10MB', 100000);
        $this->customer($target, ['router_id' => $router->id, 'mikrotik_profile' => '5M']);
        $fake = new FakeMikrotikService;
        $this->app->instance(MikrotikService::class, $fake);

        $this->artisan('network:apply-5m-router-profiles')->assertExitCode(0);

        $this->assertSame([], $fake->updates);
    }

    public function test_live_router_apply_updates_secret_and_customer_observed_profile(): void
    {
        $router = $this->router('Router A');
        $target = $this->package($router, '10MB', 'Plan 10MB', 100000);
        $customer = $this->customer($target, ['router_id' => $router->id, 'mikrotik_profile' => '5M']);
        $fake = new FakeMikrotikService([
            $customer->pppoe_user => ['profile' => '5M'],
        ]);
        $this->app->instance(MikrotikService::class, $fake);

        $this->artisan('network:apply-5m-router-profiles --apply --yes')->assertExitCode(0);

        $this->assertSame([[$customer->pppoe_user, '10MB']], $fake->updates);
        $this->assertSame('10MB', $customer->refresh()->mikrotik_profile);
        $this->assertSame('synced', $customer->mikrotik_sync_status);
        $this->assertNotNull($customer->mikrotik_synced_at);
    }

    public function test_live_router_apply_fixes_live_5m_even_when_stored_profile_already_matches_intent(): void
    {
        $router = $this->router('Router A');
        $target = $this->package($router, '10MB', 'Plan 10MB', 100000);
        $customer = $this->customer($target, ['router_id' => $router->id, 'mikrotik_profile' => '10MB']);
        $fake = new FakeMikrotikService([
            $customer->pppoe_user => ['profile' => '5M'],
        ]);
        $this->app->instance(MikrotikService::class, $fake);

        $this->artisan('network:apply-5m-router-profiles --apply --yes')->assertExitCode(0);

        $this->assertSame([[$customer->pppoe_user, '10MB']], $fake->updates);
        $this->assertSame('10MB', $customer->refresh()->mikrotik_profile);
    }

    public function test_live_router_apply_skips_isolation_like_and_missing_secrets(): void
    {
        $router = $this->router('Router A');
        $target = $this->package($router, '10MB', 'Plan 10MB', 100000);
        $isolated = $this->customer($target, [
            'router_id' => $router->id,
            'pppoe_user' => 'isolated.one',
            'mikrotik_profile' => '5M',
        ]);
        $missing = $this->customer($target, [
            'router_id' => $router->id,
            'pppoe_user' => 'missing.one',
            'mikrotik_profile' => '5M',
        ]);
        $fake = new FakeMikrotikService([
            $isolated->pppoe_user => ['profile' => 'ISOLIREBILLING'],
        ]);
        $this->app->instance(MikrotikService::class, $fake);

        $this->artisan('network:apply-5m-router-profiles --apply --yes')->assertExitCode(0);

        $this->assertSame([], $fake->updates);
        $this->assertSame('5M', $isolated->refresh()->mikrotik_profile);
        $this->assertSame('5M', $missing->refresh()->mikrotik_profile);
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
        return RouterProfile::firstOrCreate([
            'router_id' => $router->id,
            'name' => $name,
        ], [
            'rate_limit' => $rateLimit,
        ]);
    }

    private function package(Router $router, string $profile, string $name, int $price): Package
    {
        $this->profile($router, $profile, $profile === '5M' ? '2M/4M' : '5M/10M');

        return Package::create([
            'code' => 'PKG-'.uniqid(),
            'name' => $name,
            'router_id' => $router->id,
            'mikrotik_profile' => $profile,
            'rate_limit' => $profile === '5M' ? '2M/4M' : '5M/10M',
            'price' => $price,
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
            'router_id' => $package->router_id,
            'package_id' => $package->id,
            'mikrotik_profile' => $package->mikrotik_profile,
            'status' => 'active',
        ], $overrides));
    }
}

class FakeMikrotikService
{
    public array $updates = [];

    public function __construct(private array $secrets = []) {}

    public function connect(Router $router, array $options = []): self
    {
        return $this;
    }

    public function getPPPSecret(string $username): ?array
    {
        return $this->secrets[$username] ?? null;
    }

    public function updatePPPSecretProfile(string $username, string $profile): array
    {
        $this->updates[] = [$username, $profile];

        return [
            'old_profile' => $this->secrets[$username]['profile'] ?? null,
            'new_profile' => $profile,
        ];
    }

    public function disconnect(): void {}
}
