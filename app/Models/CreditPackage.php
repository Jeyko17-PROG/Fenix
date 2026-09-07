<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditPackage extends Model
{
    protected $table = 'credit_packages';

    protected $fillable = ['name', 'module', 'price_cop', 'credits', 'active'];

    protected $casts = [
        'price_cop' => 'integer',
        'credits' => 'integer',
        'active' => 'boolean',
    ];
}
