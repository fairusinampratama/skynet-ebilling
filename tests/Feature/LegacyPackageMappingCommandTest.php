<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Router;
use App\Models\RouterProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyPackageMappingCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_is_read_only(): void
    {
        $router = $this->router('Arjosari');
        $legacy = $this->legacyPackage('Paket 5M Global', 125000);
        $this->profile($router, '10MB', '5M/10M');
        $customer = $this->customer($legacy, $router, [
            'status' => 'isolated',
            'mikrotik_profile' => 'ISOLIREBILLING',
        ]);

        $packageCount = Package::count();

        $this->artisan('network:preview-legacy-package-mapping')
            ->expectsOutputToContain('Paket 5M Global')
            ->expectsOutputToContain('10MB')
            ->assertExitCode(0);

        $this->assertSame($packageCount, Package::count());
        $this->assertSame($legacy->id, $customer->refresh()->package_id);
    }

    public function test_apply_maps_legacy_5m_package_to_10mb_and_preserves_price(): void
    {
        $router = $this->router('Arjosari');
        $legacy = $this->legacyPackage('Paket 5M Global', 125000);
        $this->profile($router, '10MB', '5M/10M');
        $customer = $this->customer($legacy, $router, [
            'status' => 'isolated',
            'mikrotik_profile' => 'ISOLIREBILLING',
        ]);
        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'period' => '2026-05-01',
            'due_date' => '2026-05-20',
            'amount' => 125000,
            'status' => 'paid',
        ]);

        $this->artisan('network:preview-legacy-package-mapping --apply --yes')
            ->expectsOutputToContain('customers_reassigned')
            ->assertExitCode(0);

        $target = Package::where('router_id', $router->id)
            ->where('mikrotik_profile', '10MB')
            ->where('price', 125000)
            ->firstOrFail();

        $this->assertSame('Paket 5M Global - Arjosari - 10MB', $target->name);
        $this->assertSame('5M/10M', $target->rate_limit);
        $this->assertSame($target->id, $customer->refresh()->package_id);
        $this->assertSame('ISOLIREBILLING', $customer->mikrotik_profile);
        $this->assertSame('125000.00', $invoice->refresh()->amount);
        $this->assertSame(1, Invoice::count());
    }

    public function test_10m_falls_back_to_10m_when_10mb_is_missing(): void
    {
        $router = $this->router('Krian');
        $legacy = $this->legacyPackage('Paket 10M +', 150000);
        $this->profile($router, '10M', '25M/25M');
        $customer = $this->customer($legacy, $router);

        $this->artisan('network:preview-legacy-package-mapping --apply --yes')
            ->assertExitCode(0);

        $target = Package::where('router_id', $router->id)
            ->where('mikrotik_profile', '10M')
            ->where('price', 150000)
            ->firstOrFail();

        $this->assertSame($target->id, $customer->refresh()->package_id);
    }

    public function test_clear_speed_maps_to_matching_synced_profile(): void
    {
        $router = $this->router('Karangploso');
        $legacy = $this->legacyPackage('Paket 25M Home', 200000);
        $this->profile($router, '25MB', '12M/24M');
        $customer = $this->customer($legacy, $router);

        $this->artisan('network:preview-legacy-package-mapping --apply --yes')
            ->assertExitCode(0);

        $this->assertSame(
            '25MB',
            $customer->refresh()->package->mikrotik_profile
        );
    }

    public function test_missing_normal_target_profile_is_review_only_and_10mb_r_is_not_selected(): void
    {
        $router = $this->router('Bumiayu');
        $legacy = $this->legacyPackage('Paket UpTo 10Mbps Bumiayu', 115000);
        $this->profile($router, '10MB_R', '5M/10M');
        $customer = $this->customer($legacy, $router, [
            'mikrotik_profile' => '10MB_R',
        ]);

        $this->artisan('network:preview-legacy-package-mapping --apply --yes')
            ->expectsOutputToContain('review_rows')
            ->assertExitCode(0);

        $this->assertSame($legacy->id, $customer->refresh()->package_id);
        $this->assertSame(1, Package::count());
    }

    public function test_apply_reuses_existing_matching_target_package(): void
    {
        $router = $this->router('Arjosari');
        $legacy = $this->legacyPackage('Paket up to 5M', 100000);
        $target = Package::create([
            'code' => 'TARGET',
            'name' => 'Paket up to 5M - Arjosari - 10MB',
            'router_id' => $router->id,
            'mikrotik_profile' => '10MB',
            'rate_limit' => '5M/10M',
            'price' => 100000,
        ]);
        $this->profile($router, '10MB', '5M/10M');
        $customer = $this->customer($legacy, $router);

        $this->artisan('network:preview-legacy-package-mapping --apply --yes')
            ->assertExitCode(0);

        $this->assertSame(2, Package::count());
        $this->assertSame($target->id, $customer->refresh()->package_id);
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

    private function legacyPackage(string $name, int $price): Package
    {
        return Package::create([
            'code' => 'LEGACY-'.uniqid(),
            'name' => $name,
            'price' => $price,
        ]);
    }

    private function customer(Package $package, Router $router, array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'code' => 'CUST-'.uniqid(),
            'name' => 'Customer One',
            'phone' => '0811111111',
            'address' => 'Address',
            'pppoe_user' => 'customer.'.uniqid(),
            'router_id' => $router->id,
            'package_id' => $package->id,
            'mikrotik_profile' => 'ISOLIREBILLING',
            'previous_profile' => null,
            'status' => 'isolated',
        ], $overrides));
    }
}
