<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceBroadcast extends Model
{
    protected $fillable = [
        'invoice_id',
        'type',
        'channel',
        'status',
        'message',
        'sent_at',
        'message_id',
        'error_message',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
