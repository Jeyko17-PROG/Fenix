<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Forma de cobro manual configurada por el negocio (Nequi, Daviplata,
 * Bancolombia, link de pago, etc.) para mostrarla al cliente en el momento
 * de la venta: número/teléfono, enlace y/o QR para escanear.
 */
class MetodoPagoCobro extends Model
{
    use PerteneceAUsuario, SoftDeletes;

    protected $table = 'metodos_pago_cobro';

    protected $fillable = [
        'tipo',
        'nombre',
        'numero_cuenta',
        'enlace',
        'qr_url',
        'activo',
        'orden',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];
}
