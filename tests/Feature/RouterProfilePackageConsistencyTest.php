<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\RouterProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RouterProfilePackageConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_package_requires_router_and_synced_profile(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($admin)->post(route('packages.store'), [
            'name' => 'Package Without Router',
            'price' => 100000,
            'mikrotik_profile' => '10MB',
        ])->assertSessionHasErrors(['router_id']);

        $router = $this->router('Router A');

        $this->actingAs($admin)->post(route('packages.store'), [
            'router_id' => $router->id,
            'name' => 'Package Without Synced Profile',
            'price' => 100000,
            'mikrotik_profile' => '10MB',
        ])->assertSessionHasErrors(['mikrotik_profile']);
    }

    public function test_package_saves_router_profile_and_rate_limit(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);
        $router = $this->router('Router A');
        RouterProfile::create([
            'router_id' => $router->id,
            'name' => '10MB',
            'rate_limit' => '5M/10M',
        ]);

        $this->actingAs($admin)->post(route('packages.store'), [
            'router_id' => $router->id,
            'name' => 'Router A 10MB',
            'price' => 100000,
            'mikrotik_profile' => '10MB',
        ])->assertRedirect(route('packages.index'));

        $this->assertDatabaseHas('packages', [
            'router_id' => $router->id,
            'name' => 'Router A 10MB',
            'mikrotik_profile' => '10MB',
            'rate_limit' => '5M/10M',
            'price' => 100000,
        ]);
    }

    public function test_multiple_packages_can_use_same_router_profile(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);
        $router = $this->router('Router A');
        RouterProfile::create([
            'router_id' => $router->id,
            'name' => '10MB',
            'rate_limit' => '5M/10M',
        ]);

        foreach ([100000, 125000] as $price) {
            $this->actingAs($admin)->post(route('packages.store'), [
                'router_id' => $router->id,
                'name' => "Router A 10MB {$price}",
                'price' => $price,
                'mikrotik_profile' => '10MB',
            ])->assertRedirect(route('packages.index'));
        }

        $this->assertSame(2, Package::where('router_id', $router->id)->where('mikrotik_profile', '10MB')->count());
    }

    public function test_customer_requires_package_router_consistency(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $routerA = $this->router('Router A');
        $routerB = $this->router('Router B');
        $package = $this->package($routerA, '10MB');

        $payload = $this->customerPayload($package, [
            'router_id' => $routerB->id,
            'status' => 'active',
        ]);

        $this->actingAs($admin)->post(route('customers.store'), $payload)
            ->assertSessionHasErrors(['router_id']);
    }

    public function test_customer_active_requires_router_and_matching_package(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $router = $this->router('Router A');
        $package = $this->package($router, '10MB');

        $this->actingAs($admin)->post(route('customers.store'), $this->customerPayload($package, [
            'router_id' => null,
            'status' => 'active',
        ]))->assertSessionHasErrors(['router_id']);

        $this->actingAs($admin)->post(route('customers.store'), $this->customerPayload($package, [
            'router_id' => $router->id,
            'status' => 'active',
        ]))->assertRedirect(route('customers.index'));

        $this->assertDatabaseHas('customers', [
            'pppoe_user' => 'customer.one',
            'router_id' => $router->id,
            'package_id' => $package->id,
        ]);
    }

    public function test_packages_api_returns_only_packages_for_router(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $routerA = $this->router('Router A');
        $routerB = $this->router('Router B');
        $packageA = $this->package($routerA, '10MB');
        $packageB = $this->package($routerB, '10MB');
        $legacy = $this->package($routerA, '5M');

        $response = $this->actingAs($admin)->getJson(route('api.routers.packages', $routerA));

        $response->assertOk();
        $this->assertSame([$packageA->id], collect($response->json())->pluck('id')->all());
        $this->assertFalse(collect($response->json())->pluck('id')->contains($packageB->id));
        $this->assertFalse(collect($response->json())->pluck('id')->contains($legacy->id));
    }

    public function test_package_index_defaults_to_assignable_router_catalog(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $router = $this->router('Router A');
        $valid = $this->package($router, '10MB');
        $legacy = $this->package($router, '5M');
        $invalid = Package::create([
            'code' => 'INVALID',
            'name' => 'Invalid Profile',
            'router_id' => $router->id,
            'mikrotik_profile' => '20MB',
            'price' => 200000,
        ]);
        $global = Package::create([
            'code' => 'GLOBAL',
            'name' => 'Global Package',
            'price' => 100000,
        ]);

        $response = $this->actingAs($admin)->get(route('packages.index', [
            'router_id' => $router->id,
            'view' => 'active',
        ]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Packages/Index')
            ->where('packages.data.0.id', $valid->id)
            ->where('packages.data.0.is_assignable', true)
            ->has('packages.data', 1)
        );
    }

    public function test_package_archive_shows_non_assignable_packages(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $router = $this->router('Router A');
        $valid = $this->package($router, '10MB');
        $legacy = $this->package($router, '5M');
        $invalid = Package::create([
            'code' => 'INVALID',
            'name' => 'Invalid Profile',
            'router_id' => $router->id,
            'mikrotik_profile' => '20MB',
            'price' => 200000,
        ]);
        $global = Package::create([
            'code' => 'GLOBAL',
            'name' => 'Global Package',
            'price' => 100000,
        ]);

        $response = $this->actingAs($admin)->get(route('packages.index', ['view' => 'archive']));

        $response->assertOk()->assertInertia(function (Assert $page) use ($valid, $legacy, $invalid, $global) {
            $ids = collect($page->toArray()['props']['packages']['data'])->pluck('id');

            $this->assertFalse($ids->contains($valid->id));
            $this->assertTrue($ids->contains($legacy->id));
            $this->assertTrue($ids->contains($invalid->id));
            $this->assertTrue($ids->contains($global->id));
        });
    }

    public function test_customer_forms_receive_only_assignable_packages(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $router = $this->router('Router A');
        $valid = $this->package($router, '10MB');
        $legacy = $this->package($router, '5M');
        $invalid = Package::create([
            'code' => 'INVALID',
            'name' => 'Invalid Profile',
            'router_id' => $router->id,
            'mikrotik_profile' => '20MB',
            'price' => 200000,
        ]);

        $response = $this->actingAs($admin)->get(route('customers.create'));

        $response->assertOk()->assertInertia(function (Assert $page) use ($valid, $legacy, $invalid) {
            $ids = collect($page->toArray()['props']['packages'])->pluck('id');

            $this->assertTrue($ids->contains($valid->id));
            $this->assertFalse($ids->contains($legacy->id));
            $this->assertFalse($ids->contains($invalid->id));
        });
    }

    public function test_customer_cannot_use_archived_package(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $router = $this->router('Router A');
        $legacy = $this->package($router, '5M');
        $invalid = Package::create([
            'code' => 'INVALID',
            'name' => 'Invalid Profile',
            'router_id' => $router->id,
            'mikrotik_profile' => '20MB',
            'price' => 200000,
        ]);

        $this->actingAs($admin)->post(route('customers.store'), $this->customerPayload($legacy))
            ->assertSessionHasErrors(['package_id']);

        $this->actingAs($admin)->post(route('customers.store'), $this->customerPayload($invalid, [
            'pppoe_user' => 'customer.two',
        ]))->assertSessionHasErrors(['package_id']);
    }

    public function test_audit_and_preview_commands_are_read_only(): void
    {
        $router = $this->router('Router A');
        $package = Package::create([
            'code' => 'LEGACY',
            'name' => 'Legacy Package',
            'price' => 100000,
        ]);
        Customer::create([
            'code' => 'C001',
            'name' => 'Customer One',
            'phone' => '0811111111',
            'address' => 'Address',
            'pppoe_user' => 'customer.one',
            'router_id' => $router->id,
            'package_id' => $package->id,
            'mikrotik_profile' => '10MB',
            'status' => 'active',
        ]);

        $packageCount = Package::count();
        $customerCount = Customer::count();

        $this->artisan('network:audit-package-mapping')->assertExitCode(0);
        $this->artisan('network:audit-package-mapping --strict')->assertExitCode(1);
        $this->artisan('network:preview-package-split')->assertExitCode(0);

        $this->assertSame($packageCount, Package::count());
        $this->assertSame($customerCount, Customer::count());
    }

    public function test_package_split_apply_creates_router_profile_package_and_reassigns_eligible_customer(): void
    {
        $router = $this->router('Router A');
        RouterProfile::create([
            'router_id' => $router->id,
            'name' => '10MB',
            'rate_limit' => '5M/10M',
        ]);
        $legacy = Package::create([
            'code' => 'LEGACY',
            'name' => 'Legacy Package',
            'price' => 100000,
        ]);
        $customer = Customer::create([
            'code' => 'C001',
            'name' => 'Customer One',
            'phone' => '0811111111',
            'address' => 'Address',
            'pppoe_user' => 'customer.one',
            'router_id' => $router->id,
            'package_id' => $legacy->id,
            'mikrotik_profile' => '10MB',
            'status' => 'active',
        ]);

        $this->artisan('network:preview-package-split --apply --yes')->assertExitCode(0);

        $newPackage = Package::where('router_id', $router->id)
            ->where('mikrotik_profile', '10MB')
            ->where('price', 100000)
            ->firstOrFail();

        $this->assertSame('5M/10M', $newPackage->rate_limit);
        $this->assertSame($newPackage->id, $customer->refresh()->package_id);
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

    private function package(Router $router, string $profile): Package
    {
        RouterProfile::firstOrCreate([
            'router_id' => $router->id,
            'name' => $profile,
        ], [
            'rate_limit' => '5M/10M',
        ]);

        return Package::create([
            'code' => 'PKG-'.uniqid(),
            'name' => "{$router->name} {$profile}",
            'router_id' => $router->id,
            'mikrotik_profile' => $profile,
            'rate_limit' => '5M/10M',
            'price' => 100000,
        ]);
    }

    private function customerPayload(Package $package, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Customer One',
            'address' => 'Address',
            'phone' => '0811111111',
            'nik' => null,
            'pppoe_user' => 'customer.one',
            'package_id' => $package->id,
            'area_id' => null,
            'router_id' => $package->router_id,
            'status' => 'active',
            'geo_lat' => null,
            'geo_long' => null,
        ], $overrides);
    }
}
