<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;

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

        $limit = $request->input('limit', 25);
        $invoices = $query->paginate($limit)->withQueryString();

        return Inertia::render('Invoices/Index', [
            'invoices' => $invoices,
            'filters' => [
                'search' => $request->search,
                'status' => $request->status ?? 'all',
                'limit' => $limit,
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

    /**
     * Download invoice as PDF
     */
    public function download(Invoice $invoice)
    {
        $invoice->load(['customer.package', 'transactions']);
        
        $company = [
            'name' => Setting::get('company_name', 'PT. SKYNET LINTAS NUSANTARA'),
            'address' => Setting::get('company_address', 'Randuagung Gg VIII RT3, RW7, No.01 Singosari - Malang 65153'),
            'email' => 'cs@sky.net.id',
            'phone' => '081252095394',
        ];
        
        $manual_accounts = Setting::get('payment_channels', []);
        
        $pdf = Pdf::loadView('invoices.pdf', compact('invoice', 'company', 'manual_accounts'));
        
        return $pdf->download("Invoice-{$invoice->code}.pdf");
    }
}
