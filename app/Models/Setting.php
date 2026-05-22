<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    public const DEFAULT_PAYMENT_CHANNELS = [
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
    ];

    protected $fillable = ['key', 'value', 'type', 'group', 'label'];

    /**
     * Get a setting value by key
     */
    public static function get(string $key, $default = null)
    {
        // Cache settings to avoid hitting DB on every request
        // Clear cache when updating
        return Cache::rememberForever("setting.{$key}", function () use ($key, $default) {
            $setting = self::where('key', $key)->first();
            
            if (!$setting) {
                return $default;
            }

            return $setting->castValue($setting->value, $setting->type);
        });
    }

    /**
     * Set a setting value
     */
    public static function set(string $key, $value, $type = 'text', $group = 'general', $label = null)
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value);
            $type = 'json';
        }

        self::updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'type' => $type,
                'group' => $group,
                'label' => $label ?? ucfirst(str_replace('_', ' ', $key))
            ]
        );

        Cache::forget("setting.{$key}");
    }

    /**
     * Cast value based on type
     */
    private function castValue($value, $type)
    {
        switch ($type) {
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'int':
            case 'integer':
                return (int) $value;
            case 'json':
                return json_decode($value, true);
            default:
                return $value;
        }
    }
}
