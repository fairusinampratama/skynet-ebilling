<?php

namespace App\Console\Commands;

use App\Jobs\IsolateCustomerJob;
use App\Models\Invoice;
use Illuminate\Console\Command;

class IsolateOverdueCustomers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:isolate {--dry-run : Simulate isolation without performing actions}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find overdue customers and dispatch isolation jobs to Mikrotik';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        
        // Define Overdue Threshold (7 Days Grace Period)
        $thresholdDate = now()->subDays(7)->endOfDay();

        $this->info("Scanning for invoices overdue since: " . $thresholdDate->format('Y-m-d'));

        // Find Unpaid Invoices older than threshold
        // Group by customer to ensure we don't dispatch double jobs
        $overdueInvoices = Invoice::with('customer')
            ->where('status', 'unpaid')
            ->where('due_date', '<', $thresholdDate)
            ->get()
            ->unique('customer_id');

        $count = $overdueInvoices->count();

        if ($count === 0) {
            $this->info("No overdue customers found.");
            return;
        }

        $this->info("Found {$count} customers eligible for isolation.");

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        foreach ($overdueInvoices as $invoice) {
            $customer = $invoice->customer;

            if ($isDryRun) {
                $this->line(" [DRY RUN] Would isolate: {$customer->name} ({$customer->pppoe_user}) - Due: {$invoice->due_date->format('Y-m-d')}");
            } else {
                // Dispatch Job
                // Only if not already isolated
                if ($customer->status !== 'isolated') {
                    IsolateCustomerJob::dispatch($customer);
                    // $this->line(" Dispatched isolation job for {$customer->name}");
                }
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Isolation process completed.");
    }
}
