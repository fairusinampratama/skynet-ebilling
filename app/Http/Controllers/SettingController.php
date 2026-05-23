<?php

namespace App\Http\Controllers;

use App\Http\Requests\SettingsUpdateRequest;
use App\Models\Setting;
use App\Services\InvoiceReminderService;
use App\Services\WhatspieService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SettingController extends Controller
{
    private const ALLOWED_SETTING_KEYS = [
        'company_name',
        'company_address',
        'payment_channels',
        'whatsapp_enabled',
        'whatsapp_base_url',
        'whatsapp_device_id',
        'whatsapp_api_key',
        'whatsapp_test_number',
        'whatsapp_invoice_reminders_enabled',
        'whatsapp_isolation_notifications_enabled',
        'whatsapp_template_h_7',
        'whatsapp_template_h_5',
        'whatsapp_template_h_3',
        'whatsapp_template_h_day',
        'whatsapp_template_isolation',
    ];

    /**
     * Display the settings page.
     */
    public function index()
    {
        $settings = Setting::query()
            ->where('key', '!=', 'whatsapp_api_key')
            ->get()
            ->groupBy('group');

        return Inertia::render('Settings/Index', [
            'settings' => $settings,
            'grouped_settings' => [
                'billing' => [
                    'company_name' => Setting::get('company_name', 'Skynet Network'),
                    'company_address' => Setting::get('company_address', ''),
                    'payment_channels' => $this->paymentChannels(),
                ],
                'whatsapp' => [
                    'enabled' => Setting::get('whatsapp_enabled', true),
                    'base_url' => Setting::get('whatsapp_base_url', config('services.whatspie.url', env('WHATSPIE_BASE_URL', 'https://api.whatspie.com'))),
                    'device_id' => Setting::get('whatsapp_device_id', config('services.whatspie.device', env('WHATSPIE_DEVICE_ID', ''))),
                    'api_key_configured' => (bool) Setting::get('whatsapp_api_key', config('services.whatspie.key', env('WHATSPIE_API_KEY', ''))),
                    'test_number' => Setting::get('whatsapp_test_number', config('services.whatspie.test_number', env('WHATSPIE_TEST_NUMBER', ''))),
                    'invoice_reminders_enabled' => Setting::get('whatsapp_invoice_reminders_enabled', true),
                    'isolation_notifications_enabled' => Setting::get('whatsapp_isolation_notifications_enabled', true),
                    'templates' => [
                        'h_7' => $this->templateSetting('whatsapp_template_h_7', 'h-7'),
                        'h_5' => $this->templateSetting('whatsapp_template_h_5', 'h-5'),
                        'h_3' => $this->templateSetting('whatsapp_template_h_3', 'h-3'),
                        'h_day' => $this->templateSetting('whatsapp_template_h_day', 'h-day'),
                        'isolation' => $this->templateSetting('whatsapp_template_isolation', 'isolation'),
                    ],
                ],
            ],
        ]);
    }

    /**
     * Update settings.
     */
    public function update(SettingsUpdateRequest $request)
    {
        $validated = $request->validated();

        foreach ($validated['settings'] as $item) {
            if (! in_array($item['key'], self::ALLOWED_SETTING_KEYS, true)) {
                throw ValidationException::withMessages([
                    'settings' => "Setting {$item['key']} is not allowed.",
                ]);
            }

            if ($item['key'] === 'whatsapp_api_key' && blank($item['value'] ?? null)) {
                continue;
            }

            if ($item['key'] === 'payment_channels') {
                $item['value'] = $this->normalizePaymentChannels($item['value'] ?? []);
                $item['type'] = 'json';
                $item['group'] = 'billing';
            }

            Setting::set(
                $item['key'],
                $item['value'],
                $item['type'],
                $item['group']
            );
        }

        return back()->with('success', 'Settings updated successfully.');
    }

    private function paymentChannels(): array
    {
        $channels = Setting::get('payment_channels', []);

        if (! is_array($channels) || empty($channels)) {
            return Setting::DEFAULT_PAYMENT_CHANNELS;
        }

        return $this->normalizePaymentChannels($channels);
    }

    private function normalizePaymentChannels(mixed $channels): array
    {
        if (! is_array($channels)) {
            return [];
        }

        return collect($channels)
            ->map(fn ($channel) => [
                'bank' => trim((string) ($channel['bank'] ?? '')),
                'account_number' => trim((string) ($channel['account_number'] ?? '')),
                'account_name' => trim((string) ($channel['account_name'] ?? '')),
            ])
            ->filter(fn ($channel) => $channel['bank'] !== ''
                || $channel['account_number'] !== ''
                || $channel['account_name'] !== '')
            ->values()
            ->all();
    }

    private function templateSetting(string $key, string $type): string
    {
        $template = Setting::get($key, '');

        return blank($template)
            ? InvoiceReminderService::DEFAULT_TEMPLATES[$type]
            : (string) $template;
    }

    public function testWhatsapp(Request $request, WhatspieService $whatspie)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $deviceStatus = $whatspie->getConfiguredDeviceStatus();

        if (! ($deviceStatus['connected'] ?? false)) {
            $message = $deviceStatus['error'] ?? 'Configured Whatspie device is not connected.';

            if (! empty($deviceStatus['actual_device'])) {
                $message = "Configured device not found. Active paired device is {$deviceStatus['actual_device']}. Save this as Device ID, then test again.";
            }

            return back()->with('error', 'WhatsApp test failed: '.$message);
        }

        $response = $whatspie->sendMessage(
            $validated['phone'],
            ($validated['message'] ?? null) ?: 'Test WhatsApp dari SkyNet E-Billing.'
        );

        if (($response['simulated'] ?? false) === true) {
            return back()->with('error', 'Not sent. Local mode simulated this message because recipient does not match Local Test Number.');
        }

        if ($whatspie->wasSuccessful($response)) {
            return back()->with('success', 'WhatsApp test message sent successfully.');
        }

        return back()->with('error', 'WhatsApp test failed: '.($response['error'] ?? 'Unknown gateway error.'));
    }
}
