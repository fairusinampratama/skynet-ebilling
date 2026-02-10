<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /**
     * Display the analytics dashboard
     */
    public function index()
    {
        return inertia('Analytics/Index');
    }

    /**
     * Get monthly revenue trend data
     * Returns: total_invoiced, total_collected, outstanding per month
     */
    public function revenueTrend(Request $request)
    {
        $months = $request->input('months', 12);
        $refresh = $request->boolean('refresh', false);

        $cacheKey = "analytics.revenue_trend.{$months}";

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $data = Cache::remember($cacheKey, 3600, function () use ($months) {
            return Invoice::selectRaw("
                    DATE_FORMAT(period, '%Y-%m') as month,
                    SUM(amount) as total_invoiced,
                    SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as total_collected,
                    SUM(CASE WHEN status = 'unpaid' THEN amount ELSE 0 END) as outstanding
                ")
                ->where('period', '>=', now()->subMonths($months)->startOfMonth())
                ->groupBy('month')
                ->orderBy('month')
                ->get();
        });

        return response()->json($data);
    }

    /**
     * Get Monthly Recurring Revenue (MRR) and trend
     */
    public function mrr(Request $request)
    {
        $refresh = $request->boolean('refresh', false);

        if ($refresh) {
            Cache::forget('analytics.mrr');
        }

        $data = Cache::remember('analytics.mrr', 3600, function () {
            // Current MRR
            $currentMrr = Customer::whereIn('status', ['active', 'isolated'])
                ->join('packages', 'customers.package_id', '=', 'packages.id')
                ->sum('packages.price');

            // Last month MRR for comparison
            $lastMonthPeriod = now()->subMonth()->startOfMonth()->format('Y-m-d');
            $lastMonthMrr = Invoice::where('period', $lastMonthPeriod)
                ->sum('amount');

            // Calculate growth
            $growth = 0;
            if ($lastMonthMrr > 0) {
                $growth = round((($currentMrr - $lastMonthMrr) / $lastMonthMrr) * 100, 2);
            }

            // 6-month trend
            $trend = Invoice::selectRaw("
                    DATE_FORMAT(period, '%Y-%m') as month,
                    SUM(amount) as mrr
                ")
                ->where('period', '>=', now()->subMonths(6)->startOfMonth())
                ->groupBy('month')
                ->orderBy('month')
                ->get();

            return [
                'current_mrr' => $currentMrr,
                'growth_percentage' => $growth,
                'trend' => $trend,
            ];
        });

        return response()->json($data);
    }

    /**
     * Get collection rate statistics
     */
    public function collectionRate(Request $request)
    {
        $refresh = $request->boolean('refresh', false);

        if ($refresh) {
            Cache::forget('analytics.collection_rate');
        }

        $data = Cache::remember('analytics.collection_rate', 3600, function () {
            $currentPeriod = now()->startOfMonth()->format('Y-m-d');

            $stats = Invoice::selectRaw("
                    status,
                    COUNT(*) as count,
                    SUM(amount) as total_amount
                ")
                ->where('period', $currentPeriod)
                ->groupBy('status')
                ->get()
                ->keyBy('status');

            $totalCount = $stats->sum('count');
            $totalAmount = $stats->sum('total_amount');

            // Calculate average days to payment
            $avgDaysToPayment = Transaction::join('invoices', 'transactions.invoice_id', '=', 'invoices.id')
                ->whereMonth('transactions.paid_at', now()->month)
                ->whereYear('transactions.paid_at', now()->year)
                ->selectRaw('AVG(DATEDIFF(transactions.paid_at, invoices.generated_at)) as avg_days')
                ->value('avg_days');

            return [
                'by_status' => $stats,
                'total_count' => $totalCount,
                'total_amount' => $totalAmount,
                'collection_rate' => $totalCount > 0 ? round(($stats->get('paid')->count ?? 0) / $totalCount * 100, 2) : 0,
                'avg_days_to_payment' => round($avgDaysToPayment ?? 0, 1),
            ];
        });

        return response()->json($data);
    }

    /**
     * Get revenue breakdown by area
     */
    public function revenueByArea(Request $request)
    {
        $months = $request->input('months', 3);
        $refresh = $request->boolean('refresh', false);

        $cacheKey = "analytics.revenue_by_area.{$months}";

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $data = Cache::remember($cacheKey, 3600, function () use ($months) {
            return DB::table('areas')
                ->leftJoin('customers', 'customers.area_id', '=', 'areas.id')
                ->leftJoin('invoices', 'invoices.customer_id', '=', 'customers.id')
                ->where('invoices.period', '>=', now()->subMonths($months)->startOfMonth())
                ->selectRaw("
                    areas.name as area_name,
                    COUNT(DISTINCT customers.id) as customer_count,
                    SUM(invoices.amount) as total_billed,
                    SUM(CASE WHEN invoices.status = 'paid' THEN invoices.amount ELSE 0 END) as total_collected,
                    ROUND(
                        SUM(CASE WHEN invoices.status = 'paid' THEN invoices.amount ELSE 0 END) / 
                        NULLIF(SUM(invoices.amount), 0) * 100, 
                        2
                    ) as collection_rate
                ")
                ->groupBy('areas.id', 'areas.name')
                ->orderByDesc('total_billed')
                ->limit(10)
                ->get();
        });

        return response()->json($data);
    }

    /**
     * Get package performance analytics
     */
    public function packagePerformance(Request $request)
    {
        $months = $request->input('months', 3);
        $refresh = $request->boolean('refresh', false);

        $cacheKey = "analytics.package_performance.{$months}";

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $data = Cache::remember($cacheKey, 3600, function () use ($months) {
            return Package::leftJoin('customers', 'customers.package_id', '=', 'packages.id')
                ->leftJoin('invoices', function ($join) use ($months) {
                    $join->on('invoices.customer_id', '=', 'customers.id')
                        ->where('invoices.period', '>=', now()->subMonths($months)->startOfMonth());
                })
                ->whereIn('customers.status', ['active', 'isolated'])
                ->selectRaw("
                    packages.name as package_name,
                    packages.price,
                    COUNT(DISTINCT customers.id) as active_customers,
                    COALESCE(SUM(invoices.amount), 0) as total_revenue
                ")
                ->groupBy('packages.id', 'packages.name', 'packages.price')
                ->orderByDesc('total_revenue')
                ->get();
        });

        return response()->json($data);
    }

    /**
     * Get payment method distribution
     */
    public function paymentMethods(Request $request)
    {
        $months = $request->input('months', 6);
        $refresh = $request->boolean('refresh', false);

        $cacheKey = "analytics.payment_methods.{$months}";

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $data = Cache::remember($cacheKey, 3600, function () use ($months) {
            return Transaction::selectRaw("
                    method,
                    COUNT(*) as transaction_count,
                    SUM(amount) as total_amount
                ")
                ->where('paid_at', '>=', now()->subMonths($months))
                ->groupBy('method')
                ->get();
        });

        return response()->json($data);
    }

    /**
     * Get outstanding revenue aging report
     */
    public function outstandingAging(Request $request)
    {
        $refresh = $request->boolean('refresh', false);

        if ($refresh) {
            Cache::forget('analytics.outstanding_aging');
        }

        $data = Cache::remember('analytics.outstanding_aging', 3600, function () {
            return Invoice::selectRaw("
                    CASE 
                        WHEN DATEDIFF(NOW(), due_date) <= 30 THEN '0-30 days'
                        WHEN DATEDIFF(NOW(), due_date) <= 60 THEN '30-60 days'
                        WHEN DATEDIFF(NOW(), due_date) <= 90 THEN '60-90 days'
                        ELSE '90+ days'
                    END as age_bucket,
                    COUNT(*) as invoice_count,
                    SUM(amount) as total_amount
                ")
                ->where('status', 'unpaid')
                ->where('due_date', '<', now())
                ->groupBy('age_bucket')
                ->orderByRaw("FIELD(age_bucket, '0-30 days', '30-60 days', '60-90 days', '90+ days')")
                ->get();
        });

        return response()->json($data);
    }

    /**
     * Get customer growth trend
     */
    public function customerGrowth(Request $request)
    {
        $months = $request->input('months', 12);
        $refresh = $request->boolean('refresh', false);

        $cacheKey = "analytics.customer_growth.{$months}";

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $data = Cache::remember($cacheKey, 3600, function () use ($months) {
            // New customers by month
            $newCustomers = Customer::selectRaw("
                    DATE_FORMAT(join_date, '%Y-%m') as month,
                    COUNT(*) as new_customers
                ")
                ->where('join_date', '>=', now()->subMonths($months)->startOfMonth())
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->keyBy('month');

            // Active/Isolated count by month (current snapshot)
            $statusCounts = Customer::selectRaw("
                    status,
                    COUNT(*) as count
                ")
                ->whereIn('status', ['active', 'isolated', 'terminated'])
                ->groupBy('status')
                ->get()
                ->keyBy('status');

            return [
                'new_customers_trend' => $newCustomers,
                'current_status_breakdown' => $statusCounts,
            ];
        });

        return response()->json($data);
    }
}
