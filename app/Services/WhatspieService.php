<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatspieService
{
    protected string $baseUrl = 'https://api.whatspie.com';
    protected string $apiKey;
    protected string $deviceId;

    public function __construct()
    {
        $this->apiKey = config('services.whatspie.key', env('WHATSPIE_API_KEY', ''));
        $this->deviceId = config('services.whatspie.device', env('WHATSPIE_DEVICE_ID', ''));
    }

    /**
     * Send a text message to a phone number.
     *
     * @param string $phone The recipient's phone number (local format 08xxx is fine, will be converted)
     * @param string $message The message content
     * @return array|null The response data or null on failure
     */
    public function sendMessage(string $phone, string $message): ?array
    {
        if (empty($this->apiKey) || empty($this->deviceId)) {
            Log::warning('Whatspie credentials not configured. Skipping message.');
            return null;
        }

        // Format phone number: convert 08xxx to 628xxx
        $formattedPhone = $this->formatPhoneNumber($phone);
        
        // Ensure Device ID is clean (some users might copy paste with spaces)
        // Adjust this based on Whatspie requirements. If they require exactly what is shown (with spaces), then keep it.
        // But usually APIs prefer clean strings. The error "Device not found" suggests a mismatch.
        // Let's try sending it exactly as provided first, but maybe the surrounding quotes were the issue?
        // Actually, the previous error log showed the request was made.
        
        // Let's try to pass the device ID exactly as is, but trimming whitespace.
        $deviceId = trim($this->deviceId);

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->post("{$this->baseUrl}/messages", [
                'device' => $deviceId,
                'receiver' => $formattedPhone,
                'type' => 'chat',
                'message' => $message,
                'simulate_typing' => 1,
            ]);

            if ($response->successful()) {
                Log::info("WhatsApp sent to {$formattedPhone}");
                return $response->json();
            } else {
                Log::error("Whatspie Error: " . $response->body());
                return null;
            }
        } catch (\Exception $e) {
            Log::error("Whatspie Exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Convert local phone format to international format (62...)
     */
    private function formatPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($phone, '08')) {
            return '62' . substr($phone, 1);
        }
        
        if (str_starts_with($phone, '8')) {
            return '62' . $phone;
        }

        return $phone;
    }
}
