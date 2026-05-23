<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CleanupDelinquentCustomers extends Command
{
    protected $signature = 'customers:cleanup-delinquent
                            {--apply : Soft-delete eligible customers}
                            {--min-unpaid=3 : Minimum unpaid invoice periods required before moving customer to dismantle}
                            {--window-months=3 : Billing months to keep enforceable, including the current month}
                            {--date= : Run date for cutoff calculation (YYYY-MM-DD), defaults to today}';

    protected $description = 'Void stale unpaid invoices and soft-delete eBilling customers that exceed the delinquency window';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $minimumUnpaid = max(1, (int) $this->option('min-unpaid'));
        $windowMonths = max(1, (int) $this->option('window-months'));
        $cutoffPeriod = $this->cutoffPeriod($windowMonths);
        $staleInvoiceIds = $this->staleInvoiceIds($cutoffPeriod);
        $candidateIds = $this->candidateIds($minimumUnpaid, $cutoffPeriod);

        $this->info("Minimum unpaid periods: {$minimumUnpaid}");
        $this->info("Enforceable billing window: {$windowMonths} month(s)");
        $this->info('Stale invoice cutoff: before '.$cutoffPeriod->toDateString());
        $this->info('Stale unpaid invoices to void: '.count($staleInvoiceIds));
        $this->info('Eligible customers: '.count($candidateIds));

        if ($candidateIds === [] && $staleInvoiceIds === []) {
            return self::SUCCESS;
        }

        $previewRows = $this->previewRows($candidateIds);
        if ($previewRows->isNotEmpty()) {
            $this->table(
                ['Code', 'Name', 'Status', 'Router', 'MikroTik', 'Unpaid Periods', 'Stale Periods', 'Oldest Due'],
                $previewRows->map(fn (Customer $customer) => [
                    $customer->code,
                    $customer->name,
                    $customer->status,
                    $customer->router?->name ?? '',
                    $customer->mikrotik_sync_status ?? 'unknown',
                    $customer->invoices->pluck('period')->unique(fn ($period) => $period?->toDateString())->count(),
                    $customer->invoices->where('period', '<', $cutoffPeriod)->pluck('period')->unique(fn ($period) => $period?->toDateString())->count(),
                    $customer->invoices->min('due_date')?->toDateString() ?? '',
                ])->all()
            );
        }

        if (! $apply) {
            $this->warn('Dry run only. Re-run with --apply to void stale invoices and soft-delete eligible customers.');

            return self::SUCCESS;
        }

        $voided = $this->voidStaleInvoices($staleInvoiceIds, $cutoffPeriod);
        $deleted = 0;

        Customer::ebilling()
            ->whereKey($candidateIds)
            ->with(['router:id,name', 'invoices' => function ($query) {
                $query->where('status', 'unpaid')->orderBy('period');
            }])
            ->orderBy('id')
            ->chunkById(100, function ($customers) use (&$deleted, $minimumUnpaid) {
                DB::transaction(function () use ($customers, &$deleted, $minimumUnpaid) {
                    foreach ($customers as $customer) {
                        $unpaidInvoices = $customer->invoices;

                        activity()
                            ->performedOn($customer)
                            ->withProperties([
                                'reason' => 'three_month_delinquency_dismantle',
                                'minimum_unpaid_periods' => $minimumUnpaid,
                                'unpaid_invoice_ids' => $unpaidInvoices->pluck('id')->values()->all(),
                                'unpaid_periods' => $unpaidInvoices
                                    ->pluck('period')
                                    ->map(fn ($period) => $period?->toDateString())
                                    ->filter()
                                    ->values()
                                    ->all(),
                                'router' => $customer->router?->name,
                                'pppoe_user' => $customer->pppoe_user,
                                'mikrotik_sync_status' => $customer->mikrotik_sync_status,
                            ])
                            ->log('customer_soft_deleted_for_delinquency');

                        $customer->forceFill(['status' => 'terminated'])->saveQuietly();
                        $customer->delete();
                        $deleted++;
                    }
                });
            });

        $this->info("Voided stale unpaid invoices: {$voided}");
        $this->info("Soft-deleted customers: {$deleted}");

        return self::SUCCESS;
    }

    /**
     * @return array<int>
     */
    private function candidateIds(int $minimumUnpaid, Carbon $cutoffPeriod): array
    {
        return Customer::ebilling()
            ->where('customers.status', '!=', 'terminated')
            ->whereHas('invoices', fn (Builder $query) => $query->where('status', 'unpaid'))
            ->whereHas('invoices', fn (Builder $query) => $query
                ->where('status', 'unpaid')
                ->whereDate('period', '<', $cutoffPeriod->toDateString()))
            ->select('customers.id')
            ->join('invoices', 'invoices.customer_id', '=', 'customers.id')
            ->where('invoices.status', 'unpaid')
            ->groupBy('customers.id')
            ->havingRaw('COUNT(DISTINCT invoices.period) >= ?', [$minimumUnpaid])
            ->pluck('customers.id')
            ->all();
    }

    /**
     * @param  array<int>  $candidateIds
     */
    private function previewRows(array $candidateIds): Collection
    {
        return Customer::ebilling()
            ->whereKey($candidateIds)
            ->with([
                'router:id,name',
                'invoices' => fn ($query) => $query->where('status', 'unpaid')->orderBy('due_date'),
            ])
            ->orderBy('code')
            ->limit(50)
            ->get();
    }

    /**
     * @return array<int>
     */
    private function staleInvoiceIds(Carbon $cutoffPeriod): array
    {
        return Invoice::query()
            ->where('status', 'unpaid')
            ->whereDate('period', '<', $cutoffPeriod->toDateString())
            ->pluck('id')
            ->all();
    }

    /**
     * @param  array<int>  $invoiceIds
     */
    private function voidStaleInvoices(array $invoiceIds, Carbon $cutoffPeriod): int
    {
        if ($invoiceIds === []) {
            return 0;
        }

        $voided = 0;

        Invoice::query()
            ->whereKey($invoiceIds)
            ->where('status', 'unpaid')
            ->orderBy('id')
            ->chunkById(200, function ($invoices) use (&$voided, $cutoffPeriod) {
                DB::transaction(function () use ($invoices, &$voided, $cutoffPeriod) {
                    foreach ($invoices as $invoice) {
                        $invoice->update(['status' => 'void']);

                        activity()
                            ->performedOn($invoice)
                            ->withProperties([
                                'reason' => 'outside_rolling_billing_window',
                                'cutoff_period' => $cutoffPeriod->toDateString(),
                            ])
                            ->log('invoice_voided_for_delinquency_cleanup');

                        $voided++;
                    }
                });
            });

        return $voided;
    }

    private function cutoffPeriod(int $windowMonths): Carbon
    {
        $date = $this->option('date')
            ? Carbon::createFromFormat('Y-m-d', (string) $this->option('date'))
            : now();

        return $date->copy()->startOfMonth()->subMonths($windowMonths - 1);
    }
}
