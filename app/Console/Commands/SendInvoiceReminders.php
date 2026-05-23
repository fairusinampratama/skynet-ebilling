<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\InvoiceReminderService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendInvoiceReminders extends Command
{
    protected $signature = 'billing:send-reminders
                            {--date= : Processing date (YYYY-MM-DD), defaults to today}
                            {--dry-run : Simulate without sending WhatsApp messages or writing broadcast records}';

    protected $description = 'Send automated WhatsApp invoice reminders for unpaid invoices.';

    private const REMINDER_DAYS = [
        'h-7' => 7,
        'h-5' => 5,
        'h-3' => 3,
        'h-day' => 0,
    ];

    public function handle(InvoiceReminderService $reminders): int
    {
        $date = $this->processingDate();
        $dryRun = (bool) $this->option('dry-run');

        $this->info('Invoice reminder date: '.$date->toDateString());
        Log::info('Invoice reminder run started.', [
            'date' => $date->toDateString(),
            'dry_run' => $dryRun,
        ]);

        if ($dryRun) {
            $this->warn('DRY RUN: no WhatsApp messages or broadcast records will be created.');
        }

        foreach (self::REMINDER_DAYS as $type => $daysBeforeDue) {
            $this->processType($reminders, $date, $type, $daysBeforeDue, $dryRun);
        }

        Log::info('Invoice reminder run finished.', [
            'date' => $date->toDateString(),
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }

    private function processType(
        InvoiceReminderService $reminders,
        Carbon $date,
        string $type,
        int $daysBeforeDue,
        bool $dryRun
    ): void {
        $dueDate = $date->copy()->addDays($daysBeforeDue)->toDateString();

        $query = Invoice::query()
            ->where('status', 'unpaid')
            ->whereDate('due_date', $dueDate)
            ->whereDoesntHave('broadcasts', function ($query) use ($type) {
                $query->where('type', $type)
                    ->where('channel', InvoiceReminderService::CHANNEL);
            })
            ->with(['customer.package']);

        $total = (clone $query)->count();
        $this->info("{$type}: checking unpaid invoices due {$dueDate} ({$total} candidate(s)).");

        $stats = [
            'sent' => 0,
            'failed' => 0,
            'dry-run' => 0,
            'skipped' => 0,
        ];

        $query->chunkById(100, function ($invoices) use ($reminders, $type, $dryRun, &$stats) {
            foreach ($invoices as $invoice) {
                $result = $reminders->send($invoice, $type, $dryRun);
                $status = $result['status'] ?? 'skipped';
                $stats[$status] = ($stats[$status] ?? 0) + 1;

                if ($status === 'skipped') {
                    $this->line(" - skipped {$invoice->code}: ".($result['reason'] ?? 'unknown'));
                }
            }
        });

        $this->line(sprintf(
            '%s result: sent=%d failed=%d dry-run=%d skipped=%d',
            $type,
            $stats['sent'] ?? 0,
            $stats['failed'] ?? 0,
            $stats['dry-run'] ?? 0,
            $stats['skipped'] ?? 0
        ));
    }

    private function processingDate(): Carbon
    {
        $date = $this->option('date');

        return $date
            ? Carbon::createFromFormat('Y-m-d', (string) $date)->startOfDay()
            : now()->startOfDay();
    }
}
