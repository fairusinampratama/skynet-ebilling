<?php

namespace Tests\Feature;

use App\Jobs\IsolateCustomerJob;
use App\Jobs\ReconnectCustomerJob;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceBroadcast;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\WhatspieService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class InvoiceStabilizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_payment_rejects_paid_void_and_overpaid_invoices(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->customer();
        $paid = $this->invoice($customer, ['status' => 'paid']);
        $void = $this->invoice($customer, [
            'period' => now()->subMonth()->startOfMonth()->toDateString(),
            'status' => 'void',
        ]);
        $unpaid = $this->invoice($customer, [
            'period' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'amount' => 100000,
            'status' => 'unpaid',
        ]);

        $this->actingAs($admin)->post(route('payments.store', $paid), [
            'amount' => 1000,
            'method' => 'cash',
            'paid_at' => now()->toDateString(),
        ])->assertSessionHasErrors('amount');

        $this->actingAs($admin)->post(route('payments.store', $void), [
            'amount' => 1000,
            'method' => 'cash',
            'paid_at' => now()->toDateString(),
        ])->assertSessionHasErrors('amount');

        $this->actingAs($admin)->post(route('payments.store', $unpaid), [
            'amount' => 100001,
            'method' => 'cash',
            'paid_at' => now()->toDateString(),
        ])->assertSessionHasErrors('amount');
    }

    public function test_manual_payment_counts_only_valid_payments_and_reconnects_isolated_customer(): void
    {
        Bus::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->customer(['status' => 'isolated']);
        $invoice = $this->invoice($customer, ['amount' => 100000, 'status' => 'unpaid']);

        Transaction::create([
            'invoice_id' => $invoice->id,
            'reference' => 'FAILED-1',
            'amount' => 100000,
            'status' => 'failed',
            'method' => 'transfer',
            'paid_at' => now(),
        ]);

        $this->actingAs($admin)->post(route('payments.store', $invoice), [
            'amount' => 100000,
            'method' => 'cash',
            'paid_at' => now()->toDateString(),
        ])->assertRedirect();

        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertDatabaseHas('transactions', [
            'invoice_id' => $invoice->id,
            'status' => 'verified',
            'amount' => 100000,
        ]);
        Bus::assertDispatched(ReconnectCustomerJob::class, fn ($job) => $job->customer->is($customer));
    }

    public function test_invoice_reminder_command_sends_due_reminders_once(): void
    {
        $whatspie = new FakeInvoiceWhatspieService;
        $this->app->instance(WhatspieService::class, $whatspie);

        $invoice = $this->invoice($this->customer(), [
            'due_date' => '2026-05-29',
            'status' => 'unpaid',
        ]);

        $this->artisan('billing:send-reminders', ['--date' => '2026-05-22'])
            ->assertExitCode(0);

        $this->assertCount(1, $whatspie->messages);
        $this->assertDatabaseHas('invoice_broadcasts', [
            'invoice_id' => $invoice->id,
            'type' => 'h-7',
            'channel' => 'whatsapp',
            'status' => 'sent',
        ]);

        $this->artisan('billing:send-reminders', ['--date' => '2026-05-22'])
            ->assertExitCode(0);

        $this->assertCount(1, $whatspie->messages);
        $this->assertSame(1, InvoiceBroadcast::where('invoice_id', $invoice->id)->where('type', 'h-7')->count());
    }

    public function test_reminder_dry_run_and_missing_phone_do_not_write_broadcasts(): void
    {
        $whatspie = new FakeInvoiceWhatspieService;
        $this->app->instance(WhatspieService::class, $whatspie);

        $this->invoice($this->customer(['phone' => '']), [
            'due_date' => '2026-05-29',
            'status' => 'unpaid',
        ]);

        $this->artisan('billing:send-reminders', ['--date' => '2026-05-22', '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertCount(0, $whatspie->messages);
        $this->assertSame(0, InvoiceBroadcast::count());
    }

    public function test_overdue_check_dispatches_isolation_and_sends_single_isolation_notification(): void
    {
        Bus::fake();
        Setting::set('billing_grace_period_days', 7, 'integer', 'billing');
        $whatspie = new FakeInvoiceWhatspieService;
        $this->app->instance(WhatspieService::class, $whatspie);

        $customer = $this->customer(['router_id' => $this->router()->id, 'status' => 'active']);
        $invoice = $this->invoice($customer, [
            'due_date' => now()->subDays(10)->toDateString(),
            'status' => 'unpaid',
        ]);

        $this->artisan('billing:check-overdue')->assertExitCode(0);
        $customer->update(['status' => 'isolated']);
        $this->artisan('billing:check-overdue')->assertExitCode(0);

        Bus::assertDispatched(IsolateCustomerJob::class, 1);
        $this->assertCount(1, $whatspie->messages);
        $this->assertSame(1, InvoiceBroadcast::where('invoice_id', $invoice->id)->where('type', 'isolation')->count());
    }

    public function test_invoice_audit_command_reports_core_counts(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, ['status' => 'paid']);

        $this->artisan('billing:audit-invoices', ['--month' => now()->format('Y-m')])
            ->expectsOutputToContain('paid invoices without valid payments: 1')
            ->assertExitCode(0);
    }

    public function test_invoice_index_defaults_to_current_period_and_allows_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->customer();
        $currentInvoice = $this->invoice($customer, [
            'code' => 'INV-CURRENT-PERIOD',
            'period' => now()->startOfMonth()->toDateString(),
        ]);
        $historicalInvoice = $this->invoice($customer, [
            'code' => 'INV-HISTORICAL-PERIOD',
            'period' => now()->subYear()->startOfMonth()->toDateString(),
            'due_date' => now()->subYear()->startOfMonth()->addDays(20)->toDateString(),
        ]);

        $this->actingAs($admin)
            ->get(route('invoices.index'))
            ->assertOk()
            ->assertSee($currentInvoice->code)
            ->assertDontSee($historicalInvoice->code);

        $this->actingAs($admin)
            ->get(route('invoices.index', ['period_filter' => 'history']))
            ->assertOk()
            ->assertSee($currentInvoice->code)
            ->assertSee($historicalInvoice->code);
    }

    private function router(array $overrides = []): Router
    {
        return Router::create(array_merge([
            'name' => 'Invoice Router ' . uniqid(),
            'ip_address' => '10.99.0.' . rand(1, 254),
            'username' => 'admin',
            'password' => 'secret',
            'port' => 8728,
            'is_active' => true,
        ], $overrides));
    }

    private function customer(array $overrides = []): Customer
    {
        $package = Package::first() ?: Package::create([
            'code' => 'PKG-INVOICE',
            'name' => 'Invoice Package',
            'price' => 100000,
            'mikrotik_profile' => 'PACKAGE_PROFILE',
        ]);

        return Customer::create(array_merge([
            'code' => 'INV-CUST-' . strtoupper(substr(uniqid(), -6)),
            'name' => 'Invoice Customer',
            'phone' => '081234567890',
            'address' => 'Invoice Address',
            'pppoe_user' => 'invoice.' . substr(uniqid(), -6),
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
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => 'unpaid',
            'generated_at' => now(),
        ], $overrides));
    }
}

class FakeInvoiceWhatspieService extends WhatspieService
{
    public array $messages = [];

    public function __construct()
    {
    }

    public function sendMessage(string $phone, string $message): ?array
    {
        $this->messages[] = compact('phone', 'message');

        return ['id' => 'fake-message-' . count($this->messages)];
    }
}
