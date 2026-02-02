<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Setting;
use Inertia\Inertia;
use Illuminate\Support\Facades\Redirect;

class SettingController extends Controller
{
    /**
     * Display the settings page.
     */
    public function index()
    {
        $settings = Setting::all()->groupBy('group');
        
        return Inertia::render('Settings/Index', [
            'settings' => $settings,
            'grouped_settings' => [
                'billing' => [
                    'payment_channels' => Setting::get('payment_channels', []),
                    'company_name' => Setting::get('company_name', 'Skynet Network'),
                    'company_address' => Setting::get('company_address', ''),
                    'tripay_api_key' => Setting::get('tripay_api_key', ''),
                    'tripay_private_key' => Setting::get('tripay_private_key', ''),
                    'tripay_merchant_code' => Setting::get('tripay_merchant_code', ''),
                    'tripay_environment' => Setting::get('tripay_environment', 'sandbox'),
                ]
            ]
        ]);
    }

    /**
     * Update settings.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'nullable',
            'settings.*.group' => 'required|string',
            'settings.*.type' => 'required|string',
        ]);

        foreach ($validated['settings'] as $item) {
            Setting::set(
                $item['key'],
                $item['value'],
                $item['type'],
                $item['group']
            );
        }

        return back()->with('success', 'Settings updated successfully.');
    }
}
