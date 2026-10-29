<?php

namespace App\Shared\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida que un ID exista en una tabla RESPECTANDO el aislamiento multi-tenant.
 *
 * A diferencia de `exists:tabla,id` —que usa el query builder y por lo tanto NO
 * aplica los scopes globales de Eloquent—, esta regla consulta a través del
 * modelo, de modo que el filtro de empresa del OwnerScope se aplica solo.
 *
 * Consecuencia buscada:
 *  - un usuario normal solo puede referenciar recursos de SU empresa;
 *  - el super-admin sigue pudiendo referenciar recursos de cualquier empresa,
 *    porque OwnerScope lo excluye del filtro.
 */
class ExisteEnEmpresa implements ValidationRule
{
    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model> $modelo
     */
    public function __construct(
        private readonly string $modelo,
        private readonly string $columna = 'id',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Los campos 'nullable'/'sometimes' los resuelve Laravel por separado:
        // si no viene valor, esta regla no interviene.
        if ($value === null || $value === '') {
            return;
        }

        $existe = $this->modelo::query()
            ->where($this->columna, $value)
            ->exists();

        if (! $existe) {
            $fail('El campo :attribute hace referencia a un registro inexistente o que no pertenece a tu empresa.');
        }
    }
}
