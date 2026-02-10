<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * Display the enhanced dashboard with accounting widgets
     */
    public function index()
    {
        $currentPeriod = now()->startOfMonth()->format('Y-m-d');

        // Projected Revenue (Sum of all active customer packages)
        $projectedRevenue = Customer::where('status', 'active')
                                   ->with('package')
                                   ->get()
                                   ->sum(function($customer) {
                                       return $customer->package->price ?? 0;
                                   });

        // Actual Revenue (Sum of transactions this month)
        $actualRevenue = Transaction::whereMonth('paid_at', now()->month)
                                   ->whereYear('paid_at', now()->year)
                                   ->sum('amount');

        // Outstanding (Projected - Actual)
        $outstanding = $projectedRevenue - $actualRevenue;

        // Overdue Invoices Count (Active Overdue)
        // Invoices that are unpaid AND past due date + grace period
        $gracePeriod = (int) \App\Models\Setting::get('billing_grace_period_days', 7);
        $overdueCutoff = now()->subDays($gracePeriod);

        $overdueCount = Invoice::where('status', 'unpaid')
                              ->where('due_date', '<', $overdueCutoff)
                              ->count();

        // Active Customer Count
        $activeCustomers = Customer::where('status', 'active')->count();

        // Billing Health Stats
        $paidInvoicesCount = Invoice::where('period', $currentPeriod)->where('status', 'paid')->count();
        $unpaidInvoicesCount = Invoice::where('period', $currentPeriod)->where('status', 'unpaid')->count();
        $totalBillable = $paidInvoicesCount + $unpaidInvoicesCount;
        $collectionRate = $totalBillable > 0 ? round(($paidInvoicesCount / $totalBillable) * 100, 1) : 0;

        // Customers who should have an invoice but don't
        $customersWithInvoice = Invoice::where('period', $currentPeriod)->pluck('customer_id');
        $customersWithoutInvoice = Customer::whereIn('status', ['active', 'isolated'])
            ->whereHas('package')
            ->whereNotIn('id', $customersWithInvoice)
            ->count();

        // Recent Payments
        $recentPayments = Transaction::with(['invoice.customer', 'admin'])
            ->orderBy('paid_at', 'desc')
            ->limit(10)
            ->get();

        return Inertia::render('Dashboard', [
            'stats' => [
                'projected_revenue' => $projectedRevenue,
                'actual_revenue' => $actualRevenue,
                'outstanding' => $outstanding,
                'overdue_count' => $overdueCount,
                'active_customers' => $activeCustomers,
                'paid_invoices' => $paidInvoicesCount,
                'unpaid_invoices' => $unpaidInvoicesCount,
                'collection_rate' => $collectionRate,
                'customers_without_invoice' => $customersWithoutInvoice,
            ],
            'recent_payments' => $recentPayments,
        ]);
    }
}
