<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Documento extends Model
{
    use PerteneceAUsuario, SoftDeletes;

    protected $table = 'documentos';

    protected $fillable = [
        'owner_id',
        'tipo',
        'entidad_tipo',
        'entidad_id',
        'archivo_url',
        'created_by',
    ];

    public function firma(): HasOne
    {
        return $this->hasOne(FirmaElectronica::class, 'documento_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
