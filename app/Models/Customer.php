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

    protected $fillable = [
        'internal_id',
        'code',
        'name',
        'address',
        'phone',
        'nik',
        'geo_lat',
        'geo_long',
        'pppoe_user',
        'pppoe_pass',
        'package_id',
        'router_id',
        'status',
        'join_date',
        'ktp_photo_url',
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
        'pppoe_pass' => 'encrypted', // Security: Encrypted storage
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
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
}
