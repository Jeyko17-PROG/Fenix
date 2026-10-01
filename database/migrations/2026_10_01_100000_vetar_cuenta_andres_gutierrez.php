<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bloqueo permanente a pedido explícito del dueño de la plataforma: esta
 * cuenta no debe aparecer nunca más como super-admin ni poder iniciar
 * sesión. AuthController::login() y el middleware BloquearCuentaVetada ya
 * cierran el acceso en tiempo de ejecución; esta migración asegura que la
 * fila en sí quede en el estado correcto en cualquier entorno (no solo el
 * que se corrigió a mano el día del incidente), y revoca cualquier token
 * de acceso que ya tuviera emitido.
 */
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('users')->where('email', 'andres52885241@gmail.com')->value('id');
        if (! $id) {
            return;
        }

        DB::table('users')->where('id', $id)->update([
            'es_super_admin' => false,
            'estado' => 'SUSPENDIDO',
            'activo' => false,
        ]);

        DB::table('personal_access_tokens')
            ->where('tokenable_type', \App\IAM\Infrastructure\Persistence\Eloquent\User::class)
            ->where('tokenable_id', $id)
            ->delete();
    }

    public function down(): void
    {
        // Intencional: no hay "deshacer" para este bloqueo.
    }
};
