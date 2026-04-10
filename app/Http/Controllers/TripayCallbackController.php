<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Transaction;
use App\Services\TripayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TripayCallbackController extends Controller
{
    protected $tripay;

    public function __construct(TripayService $tripay)
    {
        $this->tripay = $tripay;
    }

    public function handle(Request $request)
    {
        // 1. Verify Signature
        if (!$this->tripay->verifyCallback($request)) {
            Log::warning('Tripay Callback Invalid Signature', $request->all());
            return response()->json(['success' => false, 'message' => 'Invalid signature'], 403);
        }

        $data = $request->all();
        $merchantRef = $data['merchant_ref'];
        $status = $data['status']; // PAID, EXPIRED, FAILED

        // Ref format: INV-{id}-{timestamp}
        $parts = explode('-', $merchantRef);
        $invoiceId = $parts[1] ?? null;

        if (!$invoiceId) {
            return response()->json(['success' => false, 'message' => 'Invalid ref format'], 400);
        }

        $invoice = Invoice::find($invoiceId);
        if (!$invoice) {
            return response()->json(['success' => false, 'message' => 'Invoice not found'], 404);
        }

        // 2. Handle Status
        // Find or create transaction record if not exists (though it should exist from pay request)
        // Actually, requestTransaction created a pending transaction. Use 'reference' to find it?
        // Tripay sends 'reference' (their ref) and 'merchant_ref' (our ref).
        
        $transaction = Transaction::where('reference', $data['reference'])->first();

        if ($status === 'PAID') {
            if ($transaction) {
                $transaction->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                ]);
            } else {
                // Should not happen if initiated from our app, but create just in case
                Transaction::create([
                    'invoice_id' => $invoice->id,
                    'reference' => $data['reference'],
                    'channel' => $data['payment_method'],
                    'amount' => $data['total_amount'],
                    'status' => 'paid',
                    'method' => 'payment_gateway',
                    'paid_at' => now(),
                ]);
            }

            // Update Invoice Status
            if ($invoice->status !== 'paid') {
                $invoice->update(['status' => 'paid']);
                
                // Reconnect Customer logic here
                // We can dispatch a job: ReconnectCustomerJob::dispatch($invoice->customer);
                $this->reconnectCustomer($invoice->customer);
                
                Log::info("Invoice #{$invoice->code} marked as PAID via Tripay.");
            }
        } elseif ($status === 'EXPIRED' || $status === 'FAILED') {
            if ($transaction) {
                $transaction->update(['status' => 'failed']);
            }
        }

        return response()->json(['success' => true]);
    }

    private function reconnectCustomer($customer)
    {
        // Simple direct call or dispatch job
        // Assuming we have a Reconnect logic in CustomerController or a Service.
        // For now, let's just log it. Ideally we call the same logic as "Reconnect" button.
        // $customer->update(['status' => 'active']);
        // RouterController::reconnect...
        
        // Dispatch job if exists
        // \App\Jobs\ReconnectCustomerJob::dispatch($customer);
        
        Log::info("Should reconnect customer: {$customer->name}");
    }
}
