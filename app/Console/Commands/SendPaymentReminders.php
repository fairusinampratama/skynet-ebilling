<?php

namespace App\Console\Commands;

use App\Jobs\IsolateCustomerJob;
use App\Models\Invoice;
use App\Services\WhatspieService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendPaymentReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:remind {--dry-run : Simulate without sending messages or isolating}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send WhatsApp payment reminders (H-5, H-Day, H+3) and isolate overdue customers.';

    private WhatspieService $whatsapp;

    public function __construct(WhatspieService $whatsapp)
    {
        parent::__construct();
        $this->whatsapp = $whatsapp;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        if ($isDryRun) {
            $this->warn("!! DRY RUN MODE - No messages sent / No Isolation !!");
        }

        $this->processReminders('h-5', 5, $isDryRun);
        $this->processReminders('h-day', 0, $isDryRun);
        $this->processOverdueAndBlock('h-plus-3', 3, $isDryRun);

        $this->info("Reminder process completed.");
    }

    private function processReminders(string $type, int $daysDiff, bool $isDryRun)
    {
        $targetDate = now()->addDays($daysDiff)->toDateString();
        
        $this->info("Checking for {$type} reminders (Due: {$targetDate})...");

        // Find unpaid invoices matching the due date logic
        // And ensure we haven't sent this specific reminder type yet
        $invoices = Invoice::where('status', 'unpaid')
            ->where('due_date', $targetDate)
            ->whereDoesntHave('broadcasts', function ($q) use ($type) {
                $q->where('type', $type);
            })
            ->with(['customer', 'customer.package'])
            ->get();

        $count = $invoices->count();
        if ($count === 0) {
            $this->line("No invoices found for {$type}.");
            return;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        foreach ($invoices as $invoice) {
            $customer = $invoice->customer;
            
            // Skip invalid phone numbers
            if (!$customer->phone) {
                $bar->advance();
                continue;
            }

            $message = $this->getMessageContent($type, $customer, $invoice);

            if ($isDryRun) {
                $this->line(" [DRY] Would send {$type} to {$customer->name} ({$customer->phone}): {$message}");
            } else {
                $this->sendBroadcast($invoice, $type, $customer->phone, $message);
            }
            
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    private function processOverdueAndBlock(string $type, int $daysOverdue, bool $isDryRun)
    {
        // Due date was X days ago
        $targetDate = now()->subDays($daysOverdue)->toDateString();
        
        // Find overdue invoices (due date <= targetDate would be catch-all, but we want to trigger exactly on H+3)
        // Actually, to be safe against missed cron jobs, we should maybe check due_date <= targetDate?
        // But the requirement says "H+3". Let's stick to specific date to avoid spamming if we re-run.
        // Or better: due_date <= targetDate AND not isolated AND reminder not sent.
        
        $this->info("Checking for {$type} Blocking (Due <= {$targetDate})...");

        $invoices = Invoice::where('status', 'unpaid')
            ->where('due_date', '<=', $targetDate) // Catch all overdue by 3+ days
            ->whereDoesntHave('broadcasts', function ($q) use ($type) {
                $q->where('type', $type); // Only process if we haven't sent the BLOCK notification
            })
            ->with(['customer', 'customer.package'])
            ->get();
            
        $count = $invoices->count();
        if ($count === 0) {
            $this->line("No overdue invoices found for blocking.");
            return;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        foreach ($invoices as $invoice) {
            $customer = $invoice->customer;

            // 1. Isolate the customer (if not already)
            if ($customer->status !== 'isolated') {
                if ($isDryRun) {
                    $this->line(" [DRY] Would ISOLATE {$customer->name}");
                } else {
                    IsolateCustomerJob::dispatch($customer);
                    $this->info(" Dispatched isolation for {$customer->name}");
                }
            }

            // 2. Send WhatsApp Notification
            if ($customer->phone) {
                $message = $this->getMessageContent($type, $customer, $invoice);
                
                if ($isDryRun) {
                    $this->line(" [DRY] Would send {$type} to {$customer->name} ({$customer->phone})");
                } else {
                    $this->sendBroadcast($invoice, $type, $customer->phone, $message);
                }
            }
            
            $bar->advance();
        }
        
        $bar->finish();
        $this->newLine();
    }

    private function sendBroadcast(Invoice $invoice, string $type, string $phone, string $message)
    {
        try {
            // Optimistically create record to lock (prevent double send race condition not essentially handled here but good practice)
            // But with Whatspie, we should send first.
            
            $response = $this->whatsapp->sendMessage($phone, $message);
            $status = $response ? 'success' : 'failed';
            $msgId = $response['id'] ?? null;
            $error = $response ? null : 'API Request Failed';

            DB::table('invoice_broadcasts')->insert([
                'invoice_id' => $invoice->id,
                'type' => $type,
                'status' => $status,
                'sent_at' => now(),
                'message_id' => $msgId,
                'error_message' => $error,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        } catch (\Exception $e) {
            Log::error("Broadcast failed for Invoice #{$invoice->id}: " . $e->getMessage());
        }
    }

    private function getMessageContent(string $type, $customer, $invoice): string
    {
        $amount = number_format($invoice->amount, 0, ',', '.');
        $dueDate = Carbon::parse($invoice->due_date)->format('d M Y');
        $period = Carbon::parse($invoice->period)->format('F Y');

        switch ($type) {
            case 'h-5':
                $link = route('public.invoice.show', $invoice->uuid);
                return "*Tagihan Internet {$period}*\n\nHalo {$customer->name},\nIni adalah pengingat bahwa tagihan internet Anda sebesar *Rp {$amount}* akan jatuh tempo pada *{$dueDate}*.\n\nBayar sekarang agar lebih mudah:\n{$link}\n\nMohon segera lakukan pembayaran untuk menghindari gangguan layanan.\n\nTerima kasih.";
            
            case 'h-day':
                $link = route('public.invoice.show', $invoice->uuid);
                return "*Jatuh Tempo Hari Ini*\n\nHalo {$customer->name},\nHari ini adalah batas akhir pembayaran tagihan internet periode {$period} sebesar *Rp {$amount}*.\n\nBayar disini:\n{$link}\n\nMohon dibayarkan segera agar layanan tetap aktif.\n\nTerima kasih.";
            
            case 'h-plus-3':
                $link = route('public.invoice.show', $invoice->uuid);
                return "*Layanan Dinonaktifkan Sementara*\n\nHalo {$customer->name},\nKami belum menerima pembayaran tagihan periode {$period} sebesar *Rp {$amount}*.\n\nLayanan internet Anda telah kami *ISOLIR* sementara.\n\nBayar & Konfirmasi otomatis disini:\n{$link}\n\nMohon segera lakukan pembayaran agar layanan aktif kembali.\n\nTerima kasih.";
            
            default:
                return "";
        }
    }
}
