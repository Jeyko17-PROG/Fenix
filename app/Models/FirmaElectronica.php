<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirmaElectronica extends Model
{
    protected $table = 'firmas_electronicas';

    protected $fillable = [
        'documento_id',
        'estado',
        'firmante_id',
        'hash_documento',
        'proveedor_firma',
        'payload_respuesta',
        'fecha_firma',
    ];

    protected $casts = [
        'payload_respuesta' => 'array',
        'fecha_firma' => 'datetime',
    ];

    public function documento(): BelongsTo
    {
        return $this->belongsTo(Documento::class, 'documento_id');
    }

    public function firmante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'firmante_id');
    }
}
