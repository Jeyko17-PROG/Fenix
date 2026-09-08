<?php

namespace App\Shared\Infrastructure\Persistence\Eloquent;

use App\Operations\Infrastructure\Persistence\Eloquent\Cliente;
use App\Shared\Infrastructure\Persistence\Concerns\PerteneceAUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Nota extends Model
{
    use PerteneceAUsuario;

    protected $table = 'notas';

    protected $fillable = ['owner_id', 'titulo', 'contenido', 'cliente_id', 'created_by'];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }
}
