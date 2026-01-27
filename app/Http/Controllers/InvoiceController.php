<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Invoice;
use App\Models\Customer;

use Inertia\Inertia;

class InvoiceController extends Controller
{
    /**
     * Display a listing of all invoices
     */
    public function index(Request $request)
    {
        $query = Invoice::query()
            ->with(['customer:id,name,code,internal_id'])
            ->when($request->search, function ($q, $search) {
                $q->whereHas('customer', function ($c) use ($search) {
                    $c->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%")
                      ->orWhere('internal_id', 'like', "%{$search}%");
                });
            })
            ->when($request->status, function ($q, $status) {
                if ($status !== 'all') {
                    $q->where('status', $status);
                }
            })
            // Default sort: Unpaid first, then newest
            ->orderByRaw("FIELD(status, 'unpaid', 'paid', 'void')")
            ->latest('period');

        $invoices = $query->paginate(50)->withQueryString();

        return Inertia::render('Invoices/Index', [
            'invoices' => $invoices,
            'filters' => [
                'search' => $request->search,
                'status' => $request->status ?? 'all',
            ],
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
