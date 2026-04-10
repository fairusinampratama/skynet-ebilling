<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TripayService
{
    protected $apiKey;
    protected $privateKey;
    protected $merchantCode;
    protected $baseUrl;

    public function __construct()
    {
        $this->apiKey = \App\Models\Setting::get('tripay_api_key');
        $this->privateKey = \App\Models\Setting::get('tripay_private_key');
        $this->merchantCode = \App\Models\Setting::get('tripay_merchant_code');
        
        $environment = \App\Models\Setting::get('tripay_environment', 'sandbox');
        $this->baseUrl = $environment === 'production' 
            ? 'https://tripay.co.id/api' 
            : 'https://tripay.co.id/api-sandbox';
    }

    /**
     * Get Payment Channels
     */
    public function getChannels()
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->get("{$this->baseUrl}/merchant/payment-channel");

            if ($response->successful()) {
                return $response->json()['data'];
            }

            Log::error('Tripay Get Channels Error: ' . $response->body());
            return [];
        } catch (\Exception $e) {
            Log::error('Tripay Exception: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Request Transaction
     */
    public function requestTransaction($invoice, $method)
    {
        $merchantRef = 'INV-' . $invoice->id . '-' . time(); // Unique ref
        
        $data = [
            'method'         => $method,
            'merchant_ref'   => $merchantRef,
            'amount'         => (int) $invoice->amount,
            'customer_name'  => $invoice->customer->name,
            'customer_email' => $invoice->customer->email ?? 'customer@skynet.id',
            'customer_phone' => $invoice->customer->phone,
            'order_items'    => [
                [
                    'sku'      => 'INET',
                    'name'     => 'Internet ' . $invoice->period->format('M Y'),
                    'price'    => (int) $invoice->amount,
                    'quantity' => 1,
                ]
            ],
            'return_url'   => route('public.invoice.show', $invoice->uuid),
            'expired_time' => (time() + (24 * 60 * 60)), // 24 hours
            'signature'    => $this->generateSignature($merchantRef, (int) $invoice->amount)
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->post("{$this->baseUrl}/transaction/create", $data);

            if ($response->successful()) {
                return $response->json()['data'];
            }

            Log::error('Tripay Transaction Error: ' . $response->body());
            throw new \Exception('Payment Gateway Error: ' . $response->json()['message'] ?? 'Unknown error');
        } catch (\Exception $e) {
            Log::error('Tripay Exception: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate Signature for Transaction Request
     */
    private function generateSignature($merchantRef, $amount)
    {
        return hash_hmac('sha256', $this->merchantCode . $merchantRef . $amount, $this->privateKey);
    }

    /**
     * Verify Callback Signature
     */
    public function verifyCallback($request)
    {
        // Tripay sends JSON body
        $json = $request->getContent();
        $signature = hash_hmac('sha256', $json, $this->privateKey);
        
        return $signature === $request->header('X-Callback-Signature');
    }
}
