<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;


class Customer extends Model
{
    use LogsActivity;

    // ...

    protected static function booted()
    {
        static::creating(function ($customer) {
            if (empty($customer->join_date)) {
                $customer->join_date = now();
            }
            
            if (empty($customer->due_day)) {
                $customer->due_day = $customer->join_date ? $customer->join_date->day : now()->day;
            }
        });

        static::updating(function ($customer) {
            // Auto-void all unpaid invoices when customer is terminated
            if ($customer->isDirty('status') && $customer->status === 'terminated') {
                $customer->invoices()->where('status', 'unpaid')->update(['status' => 'void']);
            }
        });
    }

    protected $fillable = [
        'code',
        'name',
        'address',
        'phone',
        'nik',
        'geo_lat',
        'geo_long',
        'pppoe_user',
        'package_id',
        'area_id',
        'status',
        'join_date',
        'due_day',
        'ktp_photo_url',
        'is_online',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }

    protected $casts = [
        'geo_lat' => 'decimal:8',
        'geo_long' => 'decimal:8',
        'join_date' => 'date',
        'is_online' => 'boolean',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Get the current unpaid invoice for this customer
     */
    public function currentUnpaidInvoice()
    {
        return $this->invoices()->where('status', 'unpaid')->latest('period')->first();
    }

    /**
     * Get KTP photo URL (smart accessor)
     */
    public function getKtpPhotoUrlAttribute($value): ?string
    {
        if (!$value) {
            return null;
        }

        // Filter out incomplete legacy URLs (e.g., just the directory path ending in /)
        if (str_ends_with($value, '/')) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        return asset('storage/' . $value);
    }

    /**
     * Check if customer has a KTP photo
     */
    public function hasKtpPhoto(): bool
    {
        return !empty($this->ktp_photo_url);
    }
}
