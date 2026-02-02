<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Transaction;
use App\Services\TripayService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PublicInvoiceController extends Controller
{
    protected $tripay;

    public function __construct(TripayService $tripay)
    {
        $this->tripay = $tripay;
    }

    public function show($uuid)
    {
        $invoice = Invoice::where('uuid', $uuid)->with(['customer', 'transactions'])->firstOrFail();

        // Get Channels (cache maybe ?)
        $channels = $this->tripay->getChannels();

        return Inertia::render('Public/Payment/Show', [
            'invoice' => $invoice,
            'channels' => $channels,
            'company' => [
                'name' => \App\Models\Setting::get('company_name', 'Skynet Network'),
                'address' => \App\Models\Setting::get('company_address', ''),
            ],
            'manual_accounts' => \App\Models\Setting::get('payment_channels', []),
        ]);
    }

    public function pay(Request $request, $uuid)
    {
        $invoice = Invoice::where('uuid', $uuid)->firstOrFail();
        
        $request->validate([
            'method' => 'required|string',
        ]);

        try {
            $transactionData = $this->tripay->requestTransaction($invoice, $request->method);

            // Save pending transaction
            Transaction::create([
                'invoice_id' => $invoice->id,
                'reference' => $transactionData['reference'],
                'channel' => $request->method, // e.g., QRIS
                'amount' => $transactionData['amount'],
                'status' => 'pending',
                'method' => 'payment_gateway',
            ]);

            // Save payment link to invoice for easy access
            $invoice->update(['payment_link' => $transactionData['checkout_url']]);

            return redirect()->to($transactionData['checkout_url']);

        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
