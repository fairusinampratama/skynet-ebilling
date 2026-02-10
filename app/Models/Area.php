<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    protected $fillable = ['name', 'code'];

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }
}
