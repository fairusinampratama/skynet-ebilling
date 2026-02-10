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
                    'company_name' => Setting::get('company_name', 'Skynet Network'),
                    'company_address' => Setting::get('company_address', ''),
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
