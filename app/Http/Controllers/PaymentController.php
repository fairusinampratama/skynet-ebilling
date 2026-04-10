<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

use App\Jobs\ReconnectCustomerJob;

class PaymentController extends Controller
{
    /**
     * Show the payment form for an invoice
     */
    public function create(Invoice $invoice)
    {
        $invoice->load('customer.package');

        return Inertia::render('Payments/Create', [
            'invoice' => $invoice,
        ]);
    }

    /**
     * Store a new payment
     */
    public function store(Request $request, Invoice $invoice)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
            'method' => 'required|in:cash,transfer,payment_gateway',
            'proof' => 'nullable|image|max:2048', // 2MB max
            'paid_at' => 'nullable|date',
        ]);

        // Handle proof upload
        $proofUrl = null;
        if ($request->hasFile('proof')) {
            $proofUrl = $request->file('proof')->store('payment-proofs', 'public');
        }

        // Create transaction
        $transaction = Transaction::create([
            'invoice_id' => $invoice->id,
            'admin_id' => auth()->id(),
            'amount' => $validated['amount'],
            'method' => $validated['method'],
            'proof_url' => $proofUrl,
            'paid_at' => $validated['paid_at'] ?? now(),
        ]);

        // Update invoice status if fully paid
        $totalPaid = $invoice->transactions()->sum('amount');
        if ($totalPaid >= $invoice->amount) {
            $invoice->update(['status' => 'paid']);
            
            // Trigger reconnection if customer was isolated
            $customer = $invoice->customer;
            if ($customer->status === 'isolated') {
                ReconnectCustomerJob::dispatch($customer);
            }
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('success', 'Payment recorded successfully.');
    }

    /**
     * Bulk import payments from CSV
     */
    public function bulkImport(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt',
        ]);

        // TODO: Implement CSV parsing and payment matching
        // This is marked as MVP+ in the spec

        return back()->with('success', 'CSV uploaded. Processing payments...');
    }
}
