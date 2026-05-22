<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceBroadcast;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class InvoiceReminderService
{
    public const CHANNEL = 'whatsapp';

    public const TYPES = [
        'h-7',
        'h-5',
        'h-3',
        'h-day',
        'isolation',
    ];

    public function __construct(private WhatspieService $whatspie)
    {
    }

    public function hasBroadcast(Invoice $invoice, string $type): bool
    {
        return $invoice->broadcasts()
            ->where('type', $type)
            ->where('channel', self::CHANNEL)
            ->exists();
    }

    public function send(Invoice $invoice, string $type, bool $dryRun = false): array
    {
        $invoice->loadMissing(['customer.package']);
        $customer = $invoice->customer;

        if (! in_array($type, self::TYPES, true)) {
            return ['status' => 'skipped', 'reason' => 'unknown_type'];
        }

        if (! $customer) {
            return ['status' => 'skipped', 'reason' => 'missing_customer'];
        }

        if (! $customer->phone) {
            return ['status' => 'skipped', 'reason' => 'missing_phone'];
        }

        if ($this->hasBroadcast($invoice, $type)) {
            return ['status' => 'skipped', 'reason' => 'already_sent'];
        }

        $message = $this->messageFor($invoice, $type);

        if ($dryRun) {
            return [
                'status' => 'dry-run',
                'reason' => null,
                'phone' => $customer->phone,
                'message' => $message,
            ];
        }

        $response = $this->whatspie->sendMessage($customer->phone, $message);
        $sent = (bool) $response;

        try {
            InvoiceBroadcast::create([
                'invoice_id' => $invoice->id,
                'type' => $type,
                'channel' => self::CHANNEL,
                'status' => $sent ? 'sent' : 'failed',
                'message' => $message,
                'message_id' => is_array($response) ? ($response['id'] ?? $response['message_id'] ?? null) : null,
                'error_message' => $sent ? null : 'Whatspie send failed or credentials are not configured.',
                'sent_at' => $sent ? now() : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Invoice reminder broadcast record could not be stored.', [
                'invoice_id' => $invoice->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'skipped', 'reason' => 'record_failed'];
        }

        return [
            'status' => $sent ? 'sent' : 'failed',
            'reason' => $sent ? null : 'send_failed',
        ];
    }

    public function messageFor(Invoice $invoice, string $type): string
    {
        $invoice->loadMissing(['customer.package']);
        $customer = $invoice->customer;
        $amount = number_format((float) $invoice->amount, 0, ',', '.');
        $dueDate = $invoice->due_date?->translatedFormat('d F Y') ?? Carbon::parse($invoice->due_date)->format('d M Y');
        $period = $invoice->period?->translatedFormat('F Y') ?? Carbon::parse($invoice->period)->format('F Y');
        $link = route('public.invoice.show', $invoice->uuid);
        $name = $customer?->name ?? 'Pelanggan';

        return match ($type) {
            'h-7' => "*Pengingat Tagihan Internet {$period}*\n\nHalo {$name}, tagihan internet Anda sebesar *Rp {$amount}* akan jatuh tempo pada *{$dueDate}*.\n\nLink pembayaran:\n{$link}\n\nMohon lakukan pembayaran sebelum jatuh tempo agar layanan tetap aktif. Terima kasih.",
            'h-5' => "*Pengingat Tagihan Internet {$period}*\n\nHalo {$name}, tagihan internet Anda sebesar *Rp {$amount}* akan jatuh tempo pada *{$dueDate}*.\n\nBayar melalui link berikut:\n{$link}\n\nTerima kasih.",
            'h-3' => "*Tagihan Hampir Jatuh Tempo*\n\nHalo {$name}, tagihan internet periode {$period} sebesar *Rp {$amount}* jatuh tempo pada *{$dueDate}*.\n\nLink pembayaran:\n{$link}\n\nMohon segera dibayarkan agar layanan tidak terganggu.",
            'h-day' => "*Jatuh Tempo Hari Ini*\n\nHalo {$name}, hari ini adalah batas pembayaran tagihan internet periode {$period} sebesar *Rp {$amount}*.\n\nBayar di sini:\n{$link}\n\nMohon dibayarkan hari ini agar layanan tetap aktif.",
            'isolation' => "*Layanan Diisolir Sementara*\n\nHalo {$name}, kami belum menerima pembayaran tagihan internet periode {$period} sebesar *Rp {$amount}* yang jatuh tempo pada *{$dueDate}*.\n\nLayanan internet Anda sementara kami isolir. Silakan bayar melalui link berikut agar layanan dapat aktif kembali:\n{$link}\n\nTerima kasih.",
            default => '',
        };
    }
}
