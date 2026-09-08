<?php

namespace App\Shared\Infrastructure\Persistence\Eloquent;

use App\IAM\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Archivo extends Model
{
    protected $table = 'archivos';

    protected $fillable = [
        'nombre_original',
        'ruta',
        'url',
        'tipo_mime',
        'tamano_bytes',
        'subido_por',
    ];

    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }
}
