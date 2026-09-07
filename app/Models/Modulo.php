<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de módulos de la plataforma (espejo en BD de Funcionalidades::CATALOGO).
 * La clave es la misma que usan el middleware feature:<clave> y el frontend.
 */
class Modulo extends Model
{
    protected $table = 'modulos';

    protected $fillable = ['clave', 'nombre', 'descripcion', 'activo', 'orden'];

    protected $casts = ['activo' => 'boolean'];
}
