<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Invoice extends Model
{
    use LogsActivity;

    protected $fillable = [
        'customer_id',
        'period',
        'amount',
        'status',
        'due_date',
        'generated_at',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }

    protected $casts = [
        'period' => 'date',
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'generated_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Check if invoice is overdue
     */
    public function isOverdue(): bool
    {
        return $this->status === 'unpaid' && now()->greaterThan($this->due_date);
    }

    /**
     * Get total amount paid for this invoice
     */
    public function totalPaid()
    {
        return $this->transactions()->sum('amount');
    }
}
