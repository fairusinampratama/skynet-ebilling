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
                                   ->sum('amount_paid');

        // Outstanding (Projected - Actual)
        $outstanding = $projectedRevenue - $actualRevenue;

        // Overdue Invoices Count
        $overdueCount = Invoice::where('status', 'unpaid')
                              ->where('due_date', '<', now())
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
            ],
            'recent_payments' => $recentPayments,
        ]);
    }
}
