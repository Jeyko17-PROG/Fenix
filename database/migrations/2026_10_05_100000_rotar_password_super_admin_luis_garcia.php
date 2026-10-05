<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * La contraseña anterior de luisgarciab193@gmail.com (super-admin) estaba
 * hardcodeada en texto plano en AdminUserSeeder.php y por lo tanto expuesta
 * en el repo. Se rota a una nueva contraseña y se revoca cualquier token de
 * acceso ya emitido con la contraseña vieja, para que todas las sesiones
 * activas tengan que volver a autenticarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('users')->where('email', 'luisgarciab193@gmail.com')->value('id');
        if (! $id) {
            return;
        }

        DB::table('users')->where('id', $id)->update([
            'password' => Hash::make('Jeyko$193'),
        ]);

        DB::table('personal_access_tokens')
            ->where('tokenable_type', \App\IAM\Infrastructure\Persistence\Eloquent\User::class)
            ->where('tokenable_id', $id)
            ->delete();
    }

    public function down(): void
    {
        // Intencional: no hay "deshacer" para una rotación de contraseña.
    }
};
