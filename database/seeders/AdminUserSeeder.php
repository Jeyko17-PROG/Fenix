<?php

namespace Database\Seeders;

use App\IAM\Infrastructure\Persistence\Eloquent\Plan;
use App\IAM\Infrastructure\Persistence\Eloquent\Role;
use App\IAM\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $rolAdmin = Role::where('nombre', 'Administrador')->first();
        $planPremium = Plan::where('nombre', 'Premium')->first();

        // Super Administrador de la plataforma (control total).
        // IMPORTANTE: firstOrCreate, NO updateOrCreate. db:seed corre en cada
        // arranque del contenedor (ver start.sh); con updateOrCreate, cada
        // redeploy reseteaba la contraseña de esta cuenta al valor hardcodeado
        // de aquí abajo, anulando cualquier cambio de contraseña hecho desde
        // el panel (y dejando la contraseña vieja, ya filtrada en este
        // archivo, funcionando otra vez).
        User::firstOrCreate(
            ['email' => 'luisgarciab193@gmail.com'],
            [
                'name' => 'Luis García',
                'password' => Hash::make('Jeyko$193'),
                'rol_id' => $rolAdmin?->id,
                'plan_id' => $planPremium?->id,
                'activo' => true,
                'estado' => 'ACTIVO',
                'es_super_admin' => true,
            ]
        );
        // La cuenta de andres52885241@gmail.com fue bloqueada manualmente por el
        // super-admin (acceso suspendido a pedido del dueño del negocio) y se
        // quitó de este seeder a propósito: ya no se crea ni se reactiva en
        // ningún entorno. Si alguna vez necesita volver a tener acceso, debe
        // hacerse explícitamente desde el panel de Usuarios, nunca por un seeder
        // que corre solo en cada arranque del contenedor.

        // Administrador de respaldo/pruebas: solo fuera de producción — una
        // cuenta con contraseña trivial ("password") no debe auto-crearse ni
        // reactivarse en el servidor real.
        if (! app()->environment('production')) {
            User::updateOrCreate(
                ['email' => 'admin@logix.test'],
                [
                    'name' => 'Administrador Logix',
                    'password' => Hash::make('password'),
                    'rol_id' => $rolAdmin?->id,
                    'plan_id' => $planPremium?->id,
                    'activo' => true,
                    'estado' => 'ACTIVO',
                ]
            );
        }

        // En BD nueva los seeders corren después de las migraciones: el backfill
        // crea aquí las empresas de los usuarios sembrados (es idempotente).
        \App\Business\Application\BackfillEmpresas::run();
    }
}
