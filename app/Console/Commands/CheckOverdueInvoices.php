<?php

namespace App\Console\Commands;

use App\Jobs\IsolateCustomerJob;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\InvoiceReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckOverdueInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:check-overdue {--dry-run : Run without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for overdue invoices and isolate delinquent customers';

    /**
     * Execute the console command.
     */
    public function handle(InvoiceReminderService $reminders)
    {
        $isDryRun = $this->option('dry-run');
        $graceDays = (int) Setting::get('billing_grace_period_days', 7);

        // Cutoff date is (Today - Grace Period). e.g. If today is 15th and grace is 7,
        // invoices due on or before the 8th are now actionable.
        $cutoffDate = now()->subDays($graceDays)->startOfDay();

        $this->info('Checking for overdue invoices due on or before: '.$cutoffDate->format('Y-m-d'));
        Log::info('Overdue invoice check started.', [
            'cutoff_date' => $cutoffDate->toDateString(),
            'dry_run' => $isDryRun,
            'grace_days' => $graceDays,
        ]);
        if ($isDryRun) {
            $this->warn('!! DRY RUN MODE - No actions will be taken !!');
        }

        // Only the customer's latest invoice can trigger automated isolation.
        // Historical unpaid invoices are reportable, but must not isolate a customer
        // when newer billing already shows a different state.
        $this->latestInvoiceQuery()
            ->where('due_date', '<=', $cutoffDate)
            ->whereHas('customer', function ($query) {
                $query->where('status', 'active');
            })
            ->with(['customer.router'])
            ->chunk(100, function ($invoices) use ($isDryRun, $cutoffDate, $reminders) {
                foreach ($invoices as $invoice) {
                    $this->processOverdueInvoice($invoice, $isDryRun, $cutoffDate, $reminders);
                }
            });

        if ($isDryRun) {
            $this->reportHistoricalOverdueSkippedByLatestInvoice($cutoffDate);
        }

        $this->newLine();
        $this->info('Overdue check completed.');
        Log::info('Overdue invoice check completed.', [
            'cutoff_date' => $cutoffDate->toDateString(),
            'dry_run' => $isDryRun,
        ]);
    }

    private function processOverdueInvoice($invoice, $isDryRun, $cutoffDate, InvoiceReminderService $reminders)
    {
        $customer = $invoice->customer;
        $daysOverdue = $invoice->due_date->diffInDays(now());

        $this->line("Found overdue latest invoice: <comment>{$invoice->code}</comment> for <comment>{$customer->name}</comment>");
        $this->line(" - Period: {$invoice->period->format('Y-m-d')}");
        $this->line(" - Status: {$invoice->status}");
        $this->line(" - Due Date: {$invoice->due_date->format('Y-m-d')} ({$daysOverdue} days overdue)");
        $this->line(' - Router: '.($customer->router?->name ?? 'NO_ROUTER'));
        $this->line(' - PPPoE: '.($customer->pppoe_user ?: 'NO_PPPOE'));

        if (! $customer->router_id || ! $customer->router) {
            $this->warn("   Skipping {$customer->name}: router is required for MikroTik enforcement.");
            Log::warning('Overdue isolation skipped because customer has no router.', [
                'customer_id' => $customer->id,
                'invoice_id' => $invoice->id,
            ]);

            return;
        }

        if (! $customer->pppoe_user) {
            $this->warn("   Skipping {$customer->name}: PPPoE username is required for MikroTik enforcement.");
            Log::warning('Overdue isolation skipped because customer has no PPPoE username.', [
                'customer_id' => $customer->id,
                'invoice_id' => $invoice->id,
            ]);

            return;
        }

        if ($isDryRun) {
            $this->info("   [DRY RUN] Would isolate customer {$customer->name} because latest invoice is unpaid and overdue.");
            $result = $reminders->send($invoice, 'isolation', true);
            if (($result['status'] ?? null) === 'dry-run') {
                $this->info('   [DRY RUN] Would send isolation WhatsApp notification');
            }

            return;
        }

        $this->info("   Dispatching isolation job for {$customer->name}...");

        // Log the enforcement action
        activity()
            ->performedOn($customer)
            ->withProperties([
                'invoice_id' => $invoice->id,
                'due_date' => $invoice->due_date->format('Y-m-d'),
                'days_overdue' => $daysOverdue,
                'reason' => 'payment_overdue',
            ])
            ->log('system_isolation_triggered');

        // Dispatch the job
        IsolateCustomerJob::dispatch($customer);

        $result = $reminders->send($invoice, 'isolation');
        $this->info('   Isolation notification: '.($result['status'] ?? 'skipped'));
    }

    private function latestInvoiceQuery()
    {
        return Invoice::query()
            ->where('status', 'unpaid')
            ->whereRaw('invoices.id = (
                SELECT latest_invoices.id
                FROM invoices latest_invoices
                WHERE latest_invoices.customer_id = invoices.customer_id
                ORDER BY latest_invoices.period DESC, latest_invoices.id DESC
                LIMIT 1
            )');
    }

    private function reportHistoricalOverdueSkippedByLatestInvoice($cutoffDate): void
    {
        $rows = Customer::ebilling()
            ->where('customers.status', 'active')
            ->whereExists(function ($query) use ($cutoffDate) {
                $query->selectRaw('1')
                    ->from('invoices as old_invoices')
                    ->whereColumn('old_invoices.customer_id', 'customers.id')
                    ->where('old_invoices.status', 'unpaid')
                    ->where('old_invoices.due_date', '<=', $cutoffDate);
            })
            ->join('invoices as latest_invoices', function ($join) {
                $join->on('latest_invoices.customer_id', '=', 'customers.id')
                    ->whereRaw('latest_invoices.id = (
                        SELECT newest.id
                        FROM invoices newest
                        WHERE newest.customer_id = customers.id
                        ORDER BY newest.period DESC, newest.id DESC
                        LIMIT 1
                    )');
            })
            ->where(function ($query) use ($cutoffDate) {
                $query->where('latest_invoices.status', '!=', 'unpaid')
                    ->orWhere('latest_invoices.due_date', '>', $cutoffDate);
            })
            ->select([
                'customers.code as customer_code',
                'customers.name as customer_name',
                'latest_invoices.code as invoice_code',
                'latest_invoices.period',
                'latest_invoices.status',
                'latest_invoices.due_date',
            ])
            ->orderBy('customers.code')
            ->limit(25)
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->warn('Skipped customers with historical unpaid overdue invoices because their latest invoice is not overdue unpaid:');
        $this->table(
            ['Customer', 'Name', 'Latest Invoice', 'Period', 'Status', 'Due Date'],
            $rows->map(fn ($row) => [
                $row->customer_code,
                $row->customer_name,
                $row->invoice_code,
                $this->formatDateValue($row->period),
                $row->status,
                $this->formatDateValue($row->due_date),
            ])->all()
        );
    }

    private function formatDateValue($value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }
}
