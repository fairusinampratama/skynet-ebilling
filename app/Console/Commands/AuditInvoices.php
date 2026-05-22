<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AuditInvoices extends Command
{
    protected $signature = 'billing:audit-invoices
                            {--month= : Billing month to audit (YYYY-MM), defaults to current month}
                            {--window-months=3 : Billing months to keep enforceable when reporting stale unpaid invoices}';

    protected $description = 'Audit invoice data for billing, payment, and reminder consistency.';

    private const VALID_PAYMENT_STATUSES = ['verified', 'paid'];

    public function handle(): int
    {
        $period = $this->period();

        $this->info('Auditing invoices for ' . $period->format('F Y') . ' (' . $period->toDateString() . ')');

        $this->reportMissingInvoices($period);
        $this->reportPaidWithoutValidPayments($period);
        $this->reportUnpaidFullyPaid($period);
        $this->reportAmountMismatches($period);
        $this->reportArchivedCustomerInvoices($period);
        $this->reportBadPeriods();
        $this->reportReminderReadiness($period);
        $this->reportStaleUnpaidInvoices();

        return self::SUCCESS;
    }

    private function reportMissingInvoices(Carbon $period): void
    {
        $count = Customer::ebilling()
            ->whereIn('status', ['active', 'isolated'])
            ->whereHas('package')
            ->whereDoesntHave('invoices', fn (Builder $query) => $query->whereDate('period', $period->toDateString()))
            ->count();

        $this->line("customers missing invoice: {$count}");
    }

    private function reportPaidWithoutValidPayments(Carbon $period): void
    {
        $count = Invoice::query()
            ->whereDate('period', $period->toDateString())
            ->where('status', 'paid')
            ->whereDoesntHave('transactions', fn (Builder $query) => $query->whereIn('status', self::VALID_PAYMENT_STATUSES))
            ->count();

        $this->line("paid invoices without valid payments: {$count}");
    }

    private function reportUnpaidFullyPaid(Carbon $period): void
    {
        $groups = DB::table('invoices')
            ->leftJoin('transactions', function ($join) {
                $join->on('transactions.invoice_id', '=', 'invoices.id')
                    ->whereIn('transactions.status', self::VALID_PAYMENT_STATUSES);
            })
            ->whereDate('invoices.period', $period->toDateString())
            ->where('invoices.status', 'unpaid')
            ->groupBy('invoices.id', 'invoices.amount')
            ->havingRaw('COALESCE(SUM(transactions.amount), 0) >= invoices.amount')
            ->select('invoices.id');
        $count = DB::query()->fromSub($groups, 'fully_paid_unpaid')->count();

        $this->line("unpaid invoices with full valid payments: {$count}");
    }

    private function reportAmountMismatches(Carbon $period): void
    {
        $count = DB::table('invoices')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->join('packages', 'packages.id', '=', 'customers.package_id')
            ->whereDate('invoices.period', $period->toDateString())
            ->whereColumn('invoices.amount', '<>', 'packages.price')
            ->count();

        $this->line("invoice amount mismatches package price: {$count}");
    }

    private function reportArchivedCustomerInvoices(Carbon $period): void
    {
        $count = DB::table('invoices')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->whereDate('invoices.period', $period->toDateString())
            ->whereNotNull('customers.deleted_at')
            ->count();

        $this->line("invoices linked to archived customers: {$count}");
    }

    private function reportBadPeriods(): void
    {
        $count = Invoice::query()
            ->whereRaw('DAY(period) <> 1')
            ->count();

        $duplicateGroups = DB::table('invoices')
            ->select('customer_id', 'period', DB::raw('COUNT(*) as total'))
            ->groupBy('customer_id', 'period')
            ->having('total', '>', 1);
        $duplicates = DB::query()->fromSub($duplicateGroups, 'duplicate_invoice_groups')->count();

        $this->line("non-first-day invoice periods: {$count}");
        $this->line("duplicate customer-period invoice groups: {$duplicates}");
    }

    private function reportReminderReadiness(Carbon $period): void
    {
        $missingPhone = Invoice::query()
            ->whereDate('period', $period->toDateString())
            ->where('status', 'unpaid')
            ->whereHas('customer', fn (Builder $query) => $query->whereNull('phone')->orWhere('phone', ''))
            ->count();

        $missingUuid = Invoice::query()
            ->whereDate('period', $period->toDateString())
            ->where('status', 'unpaid')
            ->whereNull('uuid')
            ->count();

        $this->line("unpaid invoices missing customer phone: {$missingPhone}");
        $this->line("unpaid invoices missing public uuid: {$missingUuid}");
    }

    private function reportStaleUnpaidInvoices(): void
    {
        $windowMonths = max(1, (int) $this->option('window-months'));
        $cutoffPeriod = now()->startOfMonth()->subMonths($windowMonths - 1);

        $count = Invoice::query()
            ->where('status', 'unpaid')
            ->whereDate('period', '<', $cutoffPeriod->toDateString())
            ->count();

        $this->line("stale unpaid invoices outside {$windowMonths}-month window: {$count}");
    }

    private function period(): Carbon
    {
        $month = $this->option('month');

        return $month
            ? Carbon::createFromFormat('Y-m', (string) $month)->startOfMonth()
            : now()->startOfMonth();
    }
}
