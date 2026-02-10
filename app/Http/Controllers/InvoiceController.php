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
            ->with(['customer:id,name,code'])
            ->when($request->search, function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('code', 'like', "%{$search}%")
                        ->orWhereHas('customer', function ($c) use ($search) {
                            $c->where('name', 'like', "%{$search}%")
                              ->orWhere('code', 'like', "%{$search}%");
                        });
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

        $limit = $request->input('limit', 20);
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
     * Show the form for creating a new invoice
     */
    public function create()
    {
        $customers = Customer::whereIn('status', ['active', 'isolated'])
            ->whereHas('package')
            ->with('package:id,name,price')
            ->select('id', 'name', 'code', 'package_id', 'due_day', 'area_id')
            ->orderBy('name')
            ->get();

        $areas = \App\Models\Area::select('id', 'name', 'code')->orderBy('name')->get();

        return Inertia::render('Invoices/Create', [
            'customers' => $customers,
            'areas' => $areas,
        ]);
    }

    /**
     * Store a newly created invoice
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'period' => 'required|date',
            'amount' => 'required|numeric|min:0',
            'due_date' => 'required|date',
        ]);

        // Convert period to 1st of month for consistency
        $period = \Carbon\Carbon::parse($validated['period'])->startOfMonth();

        // Check idempotency: prevent duplicate invoice for same customer + period
        $exists = Invoice::where('customer_id', $validated['customer_id'])
            ->where('period', $period->format('Y-m-d'))
            ->exists();

        if ($exists) {
            return back()->withErrors([
                'period' => 'An invoice already exists for this customer in this billing period.',
            ])->withInput();
        }

        Invoice::create([
            'customer_id' => $validated['customer_id'],
            'period' => $period->format('Y-m-d'),
            'amount' => $validated['amount'],
            'due_date' => $validated['due_date'],
            'status' => 'unpaid',
            'generated_at' => now(),
        ]);

        return redirect()->route('invoices.index')
            ->with('success', 'Invoice created successfully.');
    }

    /**
     * Void an unpaid invoice
     */
    public function void(Request $request, Invoice $invoice)
    {
        if ($invoice->status !== 'unpaid') {
            return back()->with('error', 'Only unpaid invoices can be voided.');
        }

        $invoice->update(['status' => 'void']);

        // Log the reason via activity log (Spatie)
        activity()
            ->performedOn($invoice)
            ->withProperties(['reason' => $request->input('reason', 'No reason provided')])
            ->log('Invoice voided');

        return back()->with('success', 'Invoice has been voided.');
    }

    /**
     * Delete an invoice (only if no payments recorded)
     */
    public function destroy(Invoice $invoice)
    {
        if ($invoice->transactions()->count() > 0) {
            return back()->with('error', 'Cannot delete an invoice with recorded payments. Void it instead.');
        }

        $invoice->delete();

        return redirect()->route('invoices.index')
            ->with('success', 'Invoice deleted successfully.');
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
        
        return $pdf->stream("Invoice-{$invoice->code}.pdf");
    }
}
