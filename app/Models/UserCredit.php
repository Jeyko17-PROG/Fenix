<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCredit extends Model
{
    protected $table = 'user_credits';

    protected $fillable = ['user_id', 'empresa_id', 'module', 'credits', 'blocked'];

    protected $casts = [
        'credits' => 'integer',
        'blocked' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
