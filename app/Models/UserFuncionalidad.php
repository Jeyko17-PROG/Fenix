<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFuncionalidad extends Model
{
    protected $table = 'user_funcionalidades';

    protected $fillable = ['user_id', 'clave', 'estado'];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
