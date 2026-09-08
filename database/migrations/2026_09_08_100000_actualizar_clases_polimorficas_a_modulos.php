<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los modelos pasaron de `App\Models\*` a los módulos (IAM, Business,
 * Operations, Billing, Shared). Tres columnas guardan el nombre de la clase
 * dentro de la base de datos, así que hay que reescribirlas o las filas
 * existentes dejan de resolver:
 *
 *  - personal_access_tokens.tokenable_type  → sesiones abiertas (Sanctum)
 *  - galeria_imagenes.imageable_type        → fotos de productos/servicios/empleados
 *  - adjuntos.adjuntable_tipo               → documentos de proveedores/clientes
 *
 * Nota: esto sigue guardando el FQCN en la BD. El paso siguiente recomendado
 * (ver docs/REESTRUCTURACION.md §5.1) es registrar un morphMap con alias
 * cortos para que ningún movimiento futuro de clases vuelva a tocar datos.
 */
return new class extends Migration
{
    /** tabla => [columna, [clase vieja => clase nueva]] */
    private function mapa(): array
    {
        $iam = 'App\\IAM\\Infrastructure\\Persistence\\Eloquent\\';
        $business = 'App\\Business\\Infrastructure\\Persistence\\Eloquent\\';
        $operations = 'App\\Operations\\Infrastructure\\Persistence\\Eloquent\\';

        return [
            'personal_access_tokens' => ['tokenable_type', [
                'App\\Models\\User' => $iam . 'User',
            ]],
            'galeria_imagenes' => ['imageable_type', [
                'App\\Models\\Producto' => $operations . 'Producto',
                'App\\Models\\Servicio' => $operations . 'Servicio',
                'App\\Models\\OperablesEmployee' => $business . 'OperablesEmployee',
            ]],
            'adjuntos' => ['adjuntable_tipo', [
                'App\\Models\\Proveedor' => $operations . 'Proveedor',
                'App\\Models\\Cliente' => $operations . 'Cliente',
            ]],
        ];
    }

    public function up(): void
    {
        $this->aplicar(false);
    }

    public function down(): void
    {
        $this->aplicar(true);
    }

    private function aplicar(bool $invertir): void
    {
        foreach ($this->mapa() as $tabla => [$columna, $clases]) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, $columna)) {
                continue;
            }
            foreach ($clases as $viejo => $nuevo) {
                [$desde, $hacia] = $invertir ? [$nuevo, $viejo] : [$viejo, $nuevo];
                DB::table($tabla)->where($columna, $desde)->update([$columna => $hacia]);
            }
        }
    }
};
