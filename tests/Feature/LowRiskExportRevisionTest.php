<?php

namespace Tests\Feature;

use App\Jobs\ReconnectCustomerJob;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;
use ZipArchive;

class LowRiskExportRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_export_includes_pppoe_last_paid_period_and_export_status_labels(): void
    {
        $this->travelTo('2026-06-06 10:00:00');

        $admin = User::factory()->create(['role' => 'admin']);
        $paidPackage = $this->package(['price' => 100000]);
        $freePackage = $this->package(['code' => 'PKG-FREE', 'price' => 0]);

        $isolated = $this->customer($paidPackage, [
            'code' => 'CUST-SUSPEND',
            'name' => 'Suspend Customer',
            'pppoe_user' => 'pppoe.suspend',
            'status' => 'isolated',
        ]);
        $free = $this->customer($freePackage, [
            'code' => 'CUST-FASUM',
            'name' => 'Free Customer',
            'pppoe_user' => 'pppoe.free',
        ]);
        $stalePaid = $this->customer($paidPackage, [
            'code' => 'CUST-STALE',
            'name' => 'Stale Paid Customer',
            'pppoe_user' => 'pppoe.stale',
            'status' => 'active',
        ]);
        $recentPaid = $this->customer($paidPackage, [
            'code' => 'CUST-RECENT',
            'name' => 'Recent Paid Customer',
            'pppoe_user' => 'pppoe.recent',
            'status' => 'active',
        ]);
        $freeStalePaid = $this->customer($freePackage, [
            'code' => 'CUST-FREE-STALE',
            'name' => 'Free Stale Paid Customer',
            'pppoe_user' => 'pppoe.free-stale',
        ]);

        $this->invoice($isolated, [
            'period' => '2026-05-01',
            'status' => 'paid',
        ]);
        $this->invoice($stalePaid, [
            'period' => '2026-02-01',
            'status' => 'paid',
        ]);
        $this->invoice($recentPaid, [
            'period' => '2026-03-01',
            'status' => 'paid',
        ]);
        $this->invoice($freeStalePaid, [
            'period' => '2026-02-01',
            'status' => 'paid',
        ]);

        $response = $this->actingAs($admin)->get(route('customers.export'));

        $response->assertOk();
        $sheet = $this->worksheetXml($response->baseResponse->getFile()->getPathname());
        $rows = $this->worksheetRows($sheet);

        $this->assertStringContainsString('Username PPPoE', $sheet);
        $this->assertStringContainsString('Periode Pembayaran Terakhir', $sheet);
        $this->assertRowContains($rows, 'pppoe.suspend', 'May 2026', 'suspend');
        $this->assertRowContains($rows, 'pppoe.free', 'fasum');
        $this->assertRowContains($rows, 'pppoe.stale', 'February 2026', 'dismantle');
        $this->assertRowContains($rows, 'pppoe.recent', 'March 2026', 'active');
        $this->assertRowContains($rows, 'pppoe.free-stale', 'February 2026', 'fasum');
        $this->assertSame('isolated', $isolated->refresh()->status);
        $this->assertSame('active', $free->refresh()->status);
        $this->assertSame('active', $stalePaid->refresh()->status);
        $this->assertSame('active', $recentPaid->refresh()->status);
    }

    public function test_invoice_export_can_filter_previous_month_and_keeps_unpaid_rows(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->customer($this->package());

        $current = $this->invoice($customer, [
            'code' => 'INV-CURRENT',
            'period' => now()->startOfMonth()->toDateString(),
        ]);
        $previous = $this->invoice($customer, [
            'code' => 'INV-PREVIOUS',
            'period' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'due_date' => now()->subMonthNoOverflow()->startOfMonth()->addDays(20)->toDateString(),
            'status' => 'unpaid',
        ]);

        $response = $this->actingAs($admin)->get(route('invoices.export', [
            'period_filter' => 'previous',
        ]));

        $response->assertOk();
        $sheet = $this->worksheetXml($response->baseResponse->getFile()->getPathname());

        $this->assertStringContainsString($previous->period->format('F Y'), $sheet);
        $this->assertStringContainsString('Belum Lunas', $sheet);
        $this->assertStringNotContainsString($current->period->format('F Y'), $sheet);
    }

    public function test_cash_payment_sets_print_invoice_flash_without_changing_reconnect_behavior(): void
    {
        Bus::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->customer($this->package(), ['status' => 'isolated']);
        $invoice = $this->invoice($customer, ['amount' => 100000, 'status' => 'unpaid']);

        $this->actingAs($admin)->post(route('payments.store', $invoice), [
            'amount' => 100000,
            'method' => 'cash',
            'paid_at' => now()->toDateString(),
        ])
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('print_invoice_id', $invoice->id);

        $this->assertSame('paid', $invoice->refresh()->status);
        Bus::assertDispatched(ReconnectCustomerJob::class, fn ($job) => $job->customer->is($customer));
    }

    private function package(array $overrides = []): Package
    {
        return Package::create(array_merge([
            'code' => 'PKG-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'Export Package',
            'price' => 100000,
            'mikrotik_profile' => 'PACKAGE_PROFILE',
        ], $overrides));
    }

    private function customer(Package $package, array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'code' => 'CUST-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'Export Customer',
            'phone' => '081234567890',
            'address' => 'Export Address',
            'pppoe_user' => 'export.'.substr(uniqid(), -6),
            'package_id' => $package->id,
            'status' => 'active',
            'due_day' => 20,
        ], $overrides));
    }

    private function invoice(Customer $customer, array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'customer_id' => $customer->id,
            'period' => now()->startOfMonth()->toDateString(),
            'amount' => 100000,
            'due_date' => now()->startOfMonth()->addDays(20)->toDateString(),
            'status' => 'unpaid',
            'generated_at' => now(),
        ], $overrides));
    }

    private function worksheetXml(string $xlsxPath): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($xlsxPath) === true);

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $this->assertIsString($sheet);

        return html_entity_decode($sheet, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * @return array<int, string>
     */
    private function worksheetRows(string $sheet): array
    {
        preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheet, $matches);

        return array_map(function (string $row): string {
            preg_match_all('/<t>(.*?)<\/t>/s', $row, $cells);

            return implode('|', array_map('strip_tags', $cells[1]));
        }, $matches[1]);
    }

    private function assertRowContains(array $rows, string ...$needles): void
    {
        foreach ($rows as $row) {
            $matches = true;
            foreach ($needles as $needle) {
                if (! str_contains($row, $needle)) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                $this->assertTrue(true);

                return;
            }
        }

        $this->fail('No exported row contains all expected values: '.implode(', ', $needles));
    }
}
