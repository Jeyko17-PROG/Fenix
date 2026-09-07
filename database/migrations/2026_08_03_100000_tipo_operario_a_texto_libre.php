<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * tipo_operario era un ENUM que había que ampliar con una migración cada vez
 * que se sumaba un rubro nuevo (primero 'lavador', luego 'barbero', ahora
 * 'tatuador'...). Se convierte a texto libre: la lista de valores permitidos
 * ya vive en el backend (OperablesEmployeeController::validar), así que el
 * ENUM en la base de datos era una restricción duplicada — y en Postgres,
 * frágil de ampliar porque requiere ubicar el nombre exacto del CHECK
 * constraint autogenerado (que puede variar entre instalaciones).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // El enum de Laravel en Postgres es un varchar + CHECK constraint
            // inline (nombre autogenerado, ej. tabla_columna_check); se busca
            // dinámicamente en vez de asumir el nombre exacto.
            $constraint = DB::selectOne("
                SELECT con.conname
                FROM pg_constraint con
                JOIN pg_class rel ON rel.oid = con.conrelid
                WHERE rel.relname = 'operables_employees'
                  AND con.contype = 'c'
                  AND pg_get_constraintdef(con.oid) LIKE '%tipo_operario%'
                LIMIT 1
            ");
            if ($constraint) {
                DB::statement('ALTER TABLE operables_employees DROP CONSTRAINT "' . $constraint->conname . '"');
            }
            DB::statement('ALTER TABLE operables_employees ALTER COLUMN tipo_operario TYPE VARCHAR(30)');
            DB::statement("ALTER TABLE operables_employees ALTER COLUMN tipo_operario SET DEFAULT 'mecanico'");
        } elseif (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE operables_employees MODIFY tipo_operario VARCHAR(30) NOT NULL DEFAULT 'mecanico'");
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás práctica (no se puede reconstruir con certeza el
        // ENUM/CHECK original de cada instalación); no se restringe de nuevo
        // para no arriesgar romper datos ya guardados con valores nuevos.
    }
};
