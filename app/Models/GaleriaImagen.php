<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class GaleriaImagen extends Model
{
    use PerteneceAUsuario;

    protected $table = 'galeria_imagenes';

    protected $fillable = ['owner_id', 'imageable_type', 'imageable_id', 'url', 'public_id', 'orden', 'created_by'];

    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }
}
