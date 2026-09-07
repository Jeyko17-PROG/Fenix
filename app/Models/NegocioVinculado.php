<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NegocioVinculado extends Model
{
    protected $table = 'negocios_vinculados';

    protected $fillable = ['user_id', 'vinculado_user_id'];
}
