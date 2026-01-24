<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Customer;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InvoiceController extends Controller
{
    /**
     * Display a listing of all invoices
     */
    public function index(Request $request)
    {
        $query = Invoice::with(['customer.package']);

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by overdue
        if ($request->has('overdue') && $request->overdue === 'true') {
            $query->where('status', 'unpaid')
                  ->where('due_date', '<', now());
        }

        // Search
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->whereHas('customer', function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $invoices = $query->orderBy('due_date', 'desc')
                          ->paginate(50)
                          ->withQueryString();

        return Inertia::render('Invoices/Index', [
            'invoices' => $invoices,
            'filters' => $request->only(['status', 'overdue', 'search']),
        ]);
    }

    /**
     * Display a specific invoice
     */
    public function show(Invoice $invoice)
    {
        $invoice->load(['customer.package', 'transactions.admin']);

        return Inertia::render('Invoices/Show', [
            'invoice' => $invoice,
        ]);
    }

    /**
     * Display all invoices for a specific customer
     */
    public function customerInvoices(Customer $customer)
    {
        $invoices = $customer->invoices()
                            ->with('transactions')
                            ->orderBy('period', 'desc')
                            ->get();

        return Inertia::render('Customers/Invoices', [
            'customer' => $customer,
            'invoices' => $invoices,
        ]);
    }
}
