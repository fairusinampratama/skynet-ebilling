<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceBroadcast;
use App\Models\Setting;
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

    private const TEMPLATE_SETTING_KEYS = [
        'h-7' => 'whatsapp_template_h_7',
        'h-5' => 'whatsapp_template_h_5',
        'h-3' => 'whatsapp_template_h_3',
        'h-day' => 'whatsapp_template_h_day',
        'isolation' => 'whatsapp_template_isolation',
    ];

    public const DEFAULT_TEMPLATES = [
        'h-7' => "*Pengingat Tagihan Internet {period}*\n\nHalo {name}, tagihan internet Anda sebesar *Rp {amount}* akan jatuh tempo pada *{due_date}*.\n\nMohon lakukan pembayaran sebelum jatuh tempo agar layanan tetap aktif. Terima kasih.",
        'h-5' => "*Pengingat Tagihan Internet {period}*\n\nHalo {name}, tagihan internet Anda sebesar *Rp {amount}* akan jatuh tempo pada *{due_date}*.\n\nMohon lakukan pembayaran sebelum jatuh tempo. Terima kasih.",
        'h-3' => "*Tagihan Hampir Jatuh Tempo*\n\nHalo {name}, tagihan internet periode {period} sebesar *Rp {amount}* jatuh tempo pada *{due_date}*.\n\nMohon segera dibayarkan agar layanan tidak terganggu.",
        'h-day' => "*Jatuh Tempo Hari Ini*\n\nHalo {name}, hari ini adalah batas pembayaran tagihan internet periode {period} sebesar *Rp {amount}*.\n\nMohon dibayarkan hari ini agar layanan tetap aktif.",
        'isolation' => "*Layanan Diisolir Sementara*\n\nHalo {name}, kami belum menerima pembayaran tagihan internet periode {period} sebesar *Rp {amount}* yang jatuh tempo pada *{due_date}*.\n\nLayanan internet Anda sementara kami isolir. Silakan lakukan pembayaran agar layanan dapat aktif kembali.\n\nTerima kasih.",
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

        if ($type === 'isolation' && ! Setting::get('whatsapp_isolation_notifications_enabled', true)) {
            return ['status' => 'skipped', 'reason' => 'isolation_notifications_disabled'];
        }

        if ($type !== 'isolation' && ! Setting::get('whatsapp_invoice_reminders_enabled', true)) {
            return ['status' => 'skipped', 'reason' => 'invoice_reminders_disabled'];
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
        $sent = $this->whatspie->wasSuccessful($response);

        try {
            InvoiceBroadcast::create([
                'invoice_id' => $invoice->id,
                'type' => $type,
                'channel' => self::CHANNEL,
                'status' => $sent ? 'sent' : 'failed',
                'message' => $message,
                'message_id' => is_array($response) ? ($response['id'] ?? $response['message_id'] ?? data_get($response, 'data.id')) : null,
                'error_message' => $sent ? null : ($response['error'] ?? 'Whatspie send failed or credentials are not configured.'),
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
        $template = $this->templateFor($type);
        if ($template === '') {
            return '';
        }

        return $this->renderTemplate($template, $invoice);
    }

    private function templateFor(string $type): string
    {
        $default = self::DEFAULT_TEMPLATES[$type] ?? '';
        $settingKey = self::TEMPLATE_SETTING_KEYS[$type] ?? null;

        if (! $settingKey) {
            return $default;
        }

        $template = Setting::get($settingKey, '');

        return blank($template) ? $default : (string) $template;
    }

    private function renderTemplate(string $template, Invoice $invoice): string
    {
        $invoice->loadMissing(['customer.package']);
        $customer = $invoice->customer;
        $amount = number_format((float) $invoice->amount, 0, ',', '.');
        $dueDate = $invoice->due_date?->translatedFormat('d F Y') ?? Carbon::parse($invoice->due_date)->format('d M Y');
        $period = $invoice->period?->translatedFormat('F Y') ?? Carbon::parse($invoice->period)->format('F Y');
        $name = $customer?->name ?? 'Pelanggan';

        return strtr($template, [
            '{name}' => $name,
            '{period}' => $period,
            '{amount}' => $amount,
            '{due_date}' => $dueDate,
        ]);
    }
}
