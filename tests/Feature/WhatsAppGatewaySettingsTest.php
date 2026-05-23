<?php

namespace Tests\Feature;

use App\Jobs\SendWaCampaignMessage;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Setting;
use App\Models\User;
use App\Models\WaCampaign;
use App\Models\WaCampaignRecipient;
use App\Services\InvoiceReminderService;
use App\Services\WhatspieService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppGatewaySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_masks_existing_api_key(): void
    {
        Setting::set('whatsapp_api_key', 'secret-token-value', 'text', 'whatsapp');
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('settings.index'));

        $response->assertOk();
        $props = $response->viewData('page')['props']['grouped_settings'];
        $this->assertTrue($props['whatsapp']['api_key_configured']);
        $this->assertArrayNotHasKey('api_key', $props['whatsapp']);
        $response->assertDontSee('secret-token-value');
    }

    public function test_settings_page_includes_saved_whatsapp_templates(): void
    {
        Setting::set('whatsapp_template_h_7', 'Custom H7 {name}', 'text', 'whatsapp');
        Setting::set('whatsapp_template_isolation', 'Custom isolation {name}', 'text', 'whatsapp');
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('settings.index'));

        $response->assertOk();
        $templates = $response->viewData('page')['props']['grouped_settings']['whatsapp']['templates'];
        $this->assertSame('Custom H7 {name}', $templates['h_7']);
        $this->assertSame('Custom isolation {name}', $templates['isolation']);
    }

    public function test_settings_page_prefills_default_whatsapp_templates(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('settings.index'));

        $response->assertOk();
        $templates = $response->viewData('page')['props']['grouped_settings']['whatsapp']['templates'];
        $this->assertStringContainsString('Pengingat Tagihan Internet', $templates['h_7']);
        $this->assertStringContainsString('{name}', $templates['h_7']);
        $this->assertStringContainsString('Layanan Diisolir Sementara', $templates['isolation']);
    }

    public function test_settings_page_prefills_default_payment_channels(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('settings.index'));

        $response->assertOk();
        $channels = $response->viewData('page')['props']['grouped_settings']['billing']['payment_channels'];
        $this->assertSame([
            [
                'bank' => 'BCA',
                'account_number' => '1234567890',
                'account_name' => 'Skynet Lintas Nusantara',
            ],
            [
                'bank' => 'Mandiri',
                'account_number' => '0987654321',
                'account_name' => 'Skynet Lintas Nusantara',
            ],
        ], $channels);
    }

    public function test_settings_update_keeps_blank_api_key_and_replaces_non_blank_key(): void
    {
        Setting::set('whatsapp_api_key', 'old-secret', 'text', 'whatsapp');
        $admin = User::factory()->create(['role' => 'admin']);

        $payload = $this->settingsPayload(['whatsapp_api_key' => '']);

        $this->actingAs($admin)->post(route('settings.update'), ['settings' => $payload])
            ->assertRedirect();
        $this->assertSame('old-secret', Setting::get('whatsapp_api_key'));

        $payload = $this->settingsPayload(['whatsapp_api_key' => 'new-secret']);
        $this->actingAs($admin)->post(route('settings.update'), ['settings' => $payload])
            ->assertRedirect();
        $this->assertSame('new-secret', Setting::get('whatsapp_api_key'));
    }

    public function test_settings_update_accepts_whatsapp_message_templates(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $payload = $this->settingsPayload([
            'whatsapp_template_h_7' => 'Reminder {name} {amount}',
            'whatsapp_template_h_5' => 'Reminder five {period}',
            'whatsapp_template_h_3' => 'Reminder three {due_date}',
            'whatsapp_template_h_day' => 'Due today {due_date}',
            'whatsapp_template_isolation' => 'Isolation {name}',
        ]);

        $this->actingAs($admin)->post(route('settings.update'), ['settings' => $payload])
            ->assertRedirect();

        $this->assertSame('Reminder {name} {amount}', Setting::get('whatsapp_template_h_7'));
        $this->assertSame('Reminder five {period}', Setting::get('whatsapp_template_h_5'));
        $this->assertSame('Reminder three {due_date}', Setting::get('whatsapp_template_h_3'));
        $this->assertSame('Due today {due_date}', Setting::get('whatsapp_template_h_day'));
        $this->assertSame('Isolation {name}', Setting::get('whatsapp_template_isolation'));
    }

    public function test_settings_update_accepts_payment_channels_and_drops_empty_rows(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $payload = $this->settingsPayload([
            'payment_channels' => [
                [
                    'bank' => ' BCA ',
                    'account_number' => ' 1234567890 ',
                    'account_name' => ' Skynet Lintas Nusantara ',
                ],
                [
                    'bank' => '',
                    'account_number' => '',
                    'account_name' => '',
                ],
                [
                    'bank' => 'Mandiri',
                    'account_number' => '0987654321',
                    'account_name' => 'Skynet Lintas Nusantara',
                ],
            ],
        ]);

        $this->actingAs($admin)->post(route('settings.update'), ['settings' => $payload])
            ->assertRedirect();

        $this->assertSame([
            [
                'bank' => 'BCA',
                'account_number' => '1234567890',
                'account_name' => 'Skynet Lintas Nusantara',
            ],
            [
                'bank' => 'Mandiri',
                'account_number' => '0987654321',
                'account_name' => 'Skynet Lintas Nusantara',
            ],
        ], Setting::get('payment_channels'));
    }

    public function test_settings_update_rejects_unknown_keys_and_scoped_admins_cannot_test_gateway(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $area = Area::create(['code' => 'SCOPED', 'name' => 'Scoped Area']);
        $admin->areas()->attach($area);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('settings.update'), [
                'settings' => [[
                    'key' => 'unknown_gateway_key',
                    'value' => 'x',
                    'type' => 'text',
                    'group' => 'whatsapp',
                ]],
            ])
            ->assertSessionHasErrors('settings');

        $this->actingAs($admin)
            ->post(route('settings.whatsapp.test'), ['phone' => '081234567890'])
            ->assertForbidden();
    }

    public function test_whatspie_uses_settings_before_env_fallback_and_formats_phone(): void
    {
        Setting::set('whatsapp_enabled', true, 'boolean', 'whatsapp');
        Setting::set('whatsapp_base_url', 'https://settings.whatspie.test', 'text', 'whatsapp');
        Setting::set('whatsapp_api_key', 'settings-token', 'text', 'whatsapp');
        Setting::set('whatsapp_device_id', 'settings-device', 'text', 'whatsapp');
        Setting::set('whatsapp_test_number', '0812-345-678', 'text', 'whatsapp');

        Http::fake([
            'settings.whatspie.test/messages' => Http::response(['id' => 'msg-1'], 200),
        ]);

        $response = app(WhatspieService::class)->sendMessage('0812-345-678', 'Hello');

        $this->assertTrue(app(WhatspieService::class)->wasSuccessful($response));
        Http::assertSent(function ($request) {
            return $request->url() === 'https://settings.whatspie.test/messages'
                && $request->hasHeader('Authorization', 'Bearer settings-token')
                && $request['device'] === 'settings-device'
                && $request['receiver'] === '62812345678';
        });
    }

    public function test_whatspie_skips_when_disabled_and_test_endpoint_reports_error(): void
    {
        Setting::set('whatsapp_enabled', false, 'boolean', 'whatsapp');
        $admin = User::factory()->create(['role' => 'admin']);

        $response = app(WhatspieService::class)->sendMessage('081234567890', 'Hello');

        $this->assertFalse(app(WhatspieService::class)->wasSuccessful($response));
        $this->assertSame('WhatsApp gateway is disabled.', $response['error']);

        $this->actingAs($admin)
            ->post(route('settings.whatsapp.test'), ['phone' => '081234567890'])
            ->assertSessionHas('error');
    }

    public function test_whatsapp_test_blocks_when_configured_device_does_not_match_paired_device(): void
    {
        Setting::set('whatsapp_enabled', true, 'boolean', 'whatsapp');
        Setting::set('whatsapp_base_url', 'https://settings.whatspie.test', 'text', 'whatsapp');
        Setting::set('whatsapp_api_key', 'settings-token', 'text', 'whatsapp');
        Setting::set('whatsapp_device_id', '6289688597253', 'text', 'whatsapp');
        $admin = User::factory()->create(['role' => 'admin']);

        Http::fake([
            'settings.whatspie.test/devices' => Http::response([
                'status' => 200,
                'message' => 'OK',
                'data' => [[
                    'id' => 5656,
                    'phone' => '6285804041950',
                    'status' => 'ACTIVE',
                    'paired_status' => 'PAIRED',
                ]],
            ], 200),
            'settings.whatspie.test/messages' => Http::response(['id' => 'should-not-send'], 200),
        ]);

        $this->actingAs($admin)
            ->post(route('settings.whatsapp.test'), ['phone' => '081234567890'])
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Configured device not found')
                && str_contains($message, '6285804041950'));

        Http::assertNotSent(fn ($request) => $request->url() === 'https://settings.whatspie.test/messages');
    }

    public function test_whatsapp_test_sends_when_configured_device_is_active_and_paired(): void
    {
        Setting::set('whatsapp_enabled', true, 'boolean', 'whatsapp');
        Setting::set('whatsapp_base_url', 'https://settings.whatspie.test', 'text', 'whatsapp');
        Setting::set('whatsapp_api_key', 'settings-token', 'text', 'whatsapp');
        Setting::set('whatsapp_device_id', '6285804041950', 'text', 'whatsapp');
        $admin = User::factory()->create(['role' => 'admin']);

        Http::fake([
            'settings.whatspie.test/devices' => Http::response([
                'status' => 200,
                'message' => 'OK',
                'data' => [[
                    'id' => 5656,
                    'phone' => '6285804041950',
                    'status' => 'ACTIVE',
                    'paired_status' => 'PAIRED',
                ]],
            ], 200),
            'settings.whatspie.test/messages' => Http::response(['id' => 'msg-1'], 200),
        ]);

        $this->actingAs($admin)
            ->post(route('settings.whatsapp.test'), [
                'phone' => '081234567890',
                'message' => 'Hello',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://settings.whatspie.test/messages'
                && $request['device'] === '6285804041950'
                && $request['receiver'] === '6281234567890';
        });
    }

    public function test_local_safeguard_simulates_non_test_numbers(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        Setting::set('whatsapp_enabled', true, 'boolean', 'whatsapp');
        Setting::set('whatsapp_api_key', 'settings-token', 'text', 'whatsapp');
        Setting::set('whatsapp_device_id', 'settings-device', 'text', 'whatsapp');
        Setting::set('whatsapp_test_number', '081111111111', 'text', 'whatsapp');

        Http::fake();

        $response = app(WhatspieService::class)->sendMessage('082222222222', 'Hello');

        $this->assertTrue(app(WhatspieService::class)->wasSuccessful($response));
        $this->assertTrue($response['simulated']);
        Http::assertNothingSent();
    }

    public function test_invoice_and_isolation_reminder_toggles_skip_sends(): void
    {
        Setting::set('whatsapp_invoice_reminders_enabled', false, 'boolean', 'whatsapp');
        Setting::set('whatsapp_isolation_notifications_enabled', false, 'boolean', 'whatsapp');
        Setting::set('billing_grace_period_days', 7, 'integer', 'billing');
        $whatspie = new FakeGatewayWhatspieService;
        $this->app->instance(WhatspieService::class, $whatspie);

        $invoice = $this->invoice($this->customer(), [
            'due_date' => '2026-05-29',
            'status' => 'unpaid',
        ]);

        $this->artisan('billing:send-reminders', ['--date' => '2026-05-22'])
            ->assertExitCode(0);

        $this->assertCount(0, $whatspie->messages);
        $this->assertSame(0, $invoice->broadcasts()->count());

        $isolationInvoice = $this->invoice($this->customer(['status' => 'active']), [
            'period' => now()->subMonth()->startOfMonth()->toDateString(),
            'due_date' => now()->subDays(10)->toDateString(),
            'status' => 'unpaid',
        ]);

        $this->artisan('billing:check-overdue')->assertExitCode(0);

        $this->assertCount(0, $whatspie->messages);
        $this->assertSame(0, $isolationInvoice->broadcasts()->count());
    }

    public function test_invoice_reminder_uses_saved_template_placeholders(): void
    {
        Setting::set('whatsapp_template_h_7', 'Halo {name}, periode {period}, Rp {amount}, jatuh tempo {due_date}', 'text', 'whatsapp');
        $customer = $this->customer(['name' => 'Template Customer']);
        $invoice = $this->invoice($customer, [
            'period' => '2026-05-01',
            'due_date' => '2026-05-29',
            'amount' => 125000,
        ]);

        $message = (new InvoiceReminderService(new FakeGatewayWhatspieService))->messageFor($invoice, 'h-7');

        $this->assertStringContainsString('Halo Template Customer', $message);
        $this->assertStringContainsString('May 2026', $message);
        $this->assertStringContainsString('Rp 125.000', $message);
        $this->assertStringContainsString('29 May 2026', $message);
        $this->assertStringNotContainsString('{link}', $message);
    }

    public function test_blank_invoice_reminder_template_falls_back_to_default(): void
    {
        Setting::set('whatsapp_template_h_7', '', 'text', 'whatsapp');
        $invoice = $this->invoice($this->customer(['name' => 'Fallback Customer']), [
            'period' => '2026-05-01',
            'due_date' => '2026-05-29',
            'amount' => 125000,
        ]);

        $message = (new InvoiceReminderService(new FakeGatewayWhatspieService))->messageFor($invoice, 'h-7');

        $this->assertStringContainsString('*Pengingat Tagihan Internet May 2026*', $message);
        $this->assertStringContainsString('Fallback Customer', $message);
        $this->assertStringContainsString('*Rp 125.000*', $message);
    }

    public function test_manual_campaign_fails_safely_when_gateway_disabled(): void
    {
        Setting::set('whatsapp_enabled', false, 'boolean', 'whatsapp');

        $campaign = WaCampaign::create([
            'name' => 'Disabled Gateway Campaign',
            'message_template' => 'Hello {name}',
            'status' => 'processing',
            'target_type' => 'custom',
            'total_recipients' => 1,
        ]);
        $recipient = WaCampaignRecipient::create([
            'wa_campaign_id' => $campaign->id,
            'phone_number' => '081234567890',
        ]);

        (new SendWaCampaignMessage($recipient))->handle(app(WhatspieService::class));

        $this->assertSame('failed', $recipient->refresh()->status);
        $this->assertSame(1, $campaign->refresh()->failed_count);
        $this->assertSame('completed', $campaign->status);
        $this->assertStringContainsString('disabled', $recipient->error_message);
    }

    private function settingsPayload(array $overrides = []): array
    {
        $values = array_merge([
            'company_name' => 'Skynet Network',
            'company_address' => 'Address',
            'payment_channels' => [
                [
                    'bank' => 'BCA',
                    'account_number' => '1234567890',
                    'account_name' => 'Skynet Lintas Nusantara',
                ],
            ],
            'whatsapp_enabled' => true,
            'whatsapp_base_url' => 'https://api.whatspie.com',
            'whatsapp_device_id' => 'device-1',
            'whatsapp_api_key' => '',
            'whatsapp_test_number' => '081234567890',
            'whatsapp_invoice_reminders_enabled' => true,
            'whatsapp_isolation_notifications_enabled' => true,
        ], $overrides);

        $types = [
            'payment_channels' => 'json',
            'whatsapp_enabled' => 'boolean',
            'whatsapp_invoice_reminders_enabled' => 'boolean',
            'whatsapp_isolation_notifications_enabled' => 'boolean',
        ];

        return collect($values)->map(fn ($value, $key) => [
            'key' => $key,
            'value' => $value,
            'type' => $types[$key] ?? 'text',
            'group' => str_starts_with($key, 'whatsapp_') ? 'whatsapp' : 'billing',
        ])->values()->all();
    }

    private function customer(array $overrides = []): Customer
    {
        $package = Package::first() ?: Package::create([
            'code' => 'PKG-WA',
            'name' => 'WA Package',
            'price' => 100000,
        ]);

        return Customer::create(array_merge([
            'code' => 'WA-CUST-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'WA Customer',
            'phone' => '081234567890',
            'address' => 'WA Address',
            'pppoe_user' => 'wa.'.substr(uniqid(), -6),
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

class FakeGatewayWhatspieService extends WhatspieService
{
    public array $messages = [];

    public function __construct() {}

    public function sendMessage(string $phone, string $message): ?array
    {
        $this->messages[] = compact('phone', 'message');

        return ['ok' => true, 'id' => 'fake-gateway-message-'.count($this->messages)];
    }
}
