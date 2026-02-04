<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    protected $fillable = [
        'router_id',
        'name',
        'mikrotik_profile', // Technical Profile Name (e.g. "10MB")
        'rate_limit',       // Display Speed (e.g. "5M/10M")
        'price',
        'bandwidth_label',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function router()
    {
        return $this->belongsTo(\App\Models\Router::class);
    }
}
