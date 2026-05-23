<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatspieService
{
    protected string $baseUrl = 'https://api.whatspie.com';

    protected ?string $apiKey;

    protected ?string $deviceId;

    protected bool $enabled;

    protected ?string $testNumber;

    public function __construct()
    {
        $this->enabled = (bool) Setting::get('whatsapp_enabled', true);
        $this->baseUrl = rtrim((string) Setting::get('whatsapp_base_url', config('services.whatspie.url', env('WHATSPIE_BASE_URL', 'https://api.whatspie.com'))), '/');
        $this->apiKey = $this->settingOrFallback('whatsapp_api_key', config('services.whatspie.key', env('WHATSPIE_API_KEY', '')));
        $this->deviceId = $this->settingOrFallback('whatsapp_device_id', config('services.whatspie.device', env('WHATSPIE_DEVICE_ID', '')));
        $this->testNumber = $this->settingOrFallback('whatsapp_test_number', config('services.whatspie.test_number', env('WHATSPIE_TEST_NUMBER')));
    }

    /**
     * Send a text message to a phone number.
     *
     * @param  string  $phone  The recipient's phone number (local format 08xxx is fine, will be converted)
     * @param  string  $message  The message content
     * @return array|null The response data, with ok=false on handled failure, or null on unexpected failure
     */
    public function sendMessage(string $phone, string $message): ?array
    {
        if (! $this->enabled) {
            Log::warning('WhatsApp gateway is disabled. Skipping message.');

            return [
                'ok' => false,
                'error' => 'WhatsApp gateway is disabled.',
            ];
        }

        if (empty($this->apiKey) || empty($this->deviceId)) {
            Log::warning('Whatspie credentials not configured. Skipping message.');

            return [
                'ok' => false,
                'error' => 'Whatspie credentials are not configured.',
            ];
        }

        // Format phone number: convert 08xxx to 628xxx
        $formattedPhone = $this->formatPhoneNumber($phone);

        // Ensure Device ID is clean
        $deviceId = trim($this->deviceId);

        // LOCAL TESTING SAFEGUARD
        if (app()->environment('local')) {
            // If test number is defined and this phone matches it, allow it.
            // Otherwise, just simulate a success response to avoid spamming real users locally.
            if (empty($this->testNumber) || $this->formatPhoneNumber($this->testNumber) !== $formattedPhone) {
                Log::info("[LOCAL SAFEGUARD] Simulated WhatsApp to {$formattedPhone}: {$message}");

                return [
                    'ok' => true,
                    'status' => 'success',
                    'message' => 'Simulated message in local environment',
                    'simulated' => true,
                ];
            }
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$this->apiKey,
            ])->post("{$this->baseUrl}/messages", [
                'device' => $deviceId,
                'receiver' => $formattedPhone,
                'type' => 'chat',
                'message' => $message,
                'simulate_typing' => 1,
            ]);

            if ($response->successful()) {
                Log::info("WhatsApp sent to {$formattedPhone}");
                $data = $response->json() ?: [];

                return array_merge($data, [
                    'ok' => true,
                    'http_status' => $response->status(),
                ]);
            } else {
                Log::error('Whatspie Error: '.$response->body());

                return [
                    'ok' => false,
                    'error' => $response->body(),
                    'http_status' => $response->status(),
                ];
            }
        } catch (\Exception $e) {
            Log::error('Whatspie Exception: '.$e->getMessage());

            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getConfiguredDeviceStatus(): array
    {
        if (! $this->enabled) {
            return [
                'ok' => false,
                'connected' => false,
                'configured_device' => $this->deviceId,
                'error' => 'WhatsApp gateway is disabled.',
            ];
        }

        if (empty($this->apiKey) || empty($this->deviceId)) {
            return [
                'ok' => false,
                'connected' => false,
                'configured_device' => $this->deviceId,
                'error' => 'Whatspie credentials are not configured.',
            ];
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$this->apiKey,
            ])->get("{$this->baseUrl}/devices");

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'connected' => false,
                    'configured_device' => $this->deviceId,
                    'error' => $response->body(),
                    'http_status' => $response->status(),
                ];
            }

            $devices = collect($response->json('data') ?: []);
            $device = $devices->first(function (array $device) {
                return (string) ($device['phone'] ?? '') === (string) $this->deviceId
                    || (string) ($device['id'] ?? '') === (string) $this->deviceId;
            });
            $activeDevice = $devices->first(fn (array $device) => ($device['paired_status'] ?? null) === 'PAIRED');

            if (! $device) {
                return [
                    'ok' => true,
                    'connected' => false,
                    'configured_device' => $this->deviceId,
                    'actual_device' => $activeDevice['phone'] ?? null,
                    'error' => 'Configured Whatspie device was not found.',
                ];
            }

            $connected = ($device['status'] ?? null) === 'ACTIVE'
                && ($device['paired_status'] ?? null) === 'PAIRED';

            return [
                'ok' => true,
                'connected' => $connected,
                'configured_device' => $this->deviceId,
                'actual_device' => $device['phone'] ?? null,
                'status' => $device['status'] ?? null,
                'paired_status' => $device['paired_status'] ?? null,
                'error' => $connected ? null : 'Configured Whatspie device is not connected. Reconnect this device in Whatspie, then test again.',
            ];
        } catch (\Exception $e) {
            return [
                'ok' => false,
                'connected' => false,
                'configured_device' => $this->deviceId,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function wasSuccessful(?array $response): bool
    {
        if ($response === null) {
            return false;
        }

        if (array_key_exists('ok', $response)) {
            return (bool) $response['ok'];
        }

        return true;
    }

    /**
     * Normalize phone number to international Indonesian format (62...).
     * Handles: +62xxx, 62xxx, 08xxx, 8xxx, spaces, dashes, dots.
     */
    private function formatPhoneNumber(string $phone): string
    {
        // Strip all non-numeric characters (spaces, dashes, dots, +)
        $phone = preg_replace('/[^0-9]/', '', trim($phone));

        // Remove leading zeros beyond one (e.g. 0008 -> keep processing)
        // 08xxxxxxxxx -> 628xxxxxxxxx
        if (str_starts_with($phone, '0')) {
            $phone = '62'.substr($phone, 1);
        }

        // 8xxxxxxxxx (without country code) -> 628xxxxxxxxx
        elseif (str_starts_with($phone, '8')) {
            $phone = '62'.$phone;
        }

        // Already correct: 62xxxxxxxxx — no change needed
        return $phone;
    }

    private function settingOrFallback(string $key, mixed $fallback): ?string
    {
        $value = Setting::get($key, null);

        if ($value === null || $value === '') {
            $value = $fallback;
        }

        return $value === null ? null : (string) $value;
    }
}
