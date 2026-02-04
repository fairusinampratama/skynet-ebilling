<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Router extends Model
{
    protected $fillable = [
        'name',
        'ip_address',
        'port',
        'username',
        'password',
        'is_active',
        'connection_status',
        'last_scanned_at',
        'last_scan_customers_count',
        'current_online_count',
        'cpu_load',
        'uptime',
        'version',
        'board_name',
        'last_health_check_at',
        'total_pppoe_count',
    ];

    protected $casts = [
        'password' => 'encrypted',
        'is_active' => 'boolean',
        'connection_status' => 'string',
        'last_scanned_at' => 'datetime',
        'last_health_check_at' => 'datetime',
    ];

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function profiles()
    {
        return $this->hasMany(RouterProfile::class);
    }
}
