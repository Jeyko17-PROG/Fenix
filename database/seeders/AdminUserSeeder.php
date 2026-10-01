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
        User::updateOrCreate(
            ['email' => 'luisgarciab193@gmail.com'],
            [
                'name' => 'Luis García',
                'password' => Hash::make('1030680290'),
                'rol_id' => $rolAdmin?->id,
                'plan_id' => $planPremium?->id,
                'activo' => true,
                'estado' => 'ACTIVO',
                'es_super_admin' => true,
            ]
        );
        // IMPORTANTE: firstOrCreate, NO updateOrCreate. Esta cuenta fue bloqueada
        // manualmente por el super-admin (acceso suspendido a pedido del dueño del
        // negocio). db:seed corre en cada arranque del contenedor (ver start.sh);
        // con updateOrCreate, cada redeploy reactivaba la cuenta y reseteaba su
        // contraseña al valor de aquí abajo, anulando el bloqueo de seguridad.
        User::firstOrCreate(
            ['email' => 'andres52885241@gmail.com'],
            [
                'name' => 'Andrés Gutiérrez Hurtado',
                'password' => Hash::make('12345Aa@'),
                'rol_id' => $rolAdmin?->id,
                'plan_id' => $planPremium?->id,
                'activo' => true,
                'estado' => 'ACTIVO',
                'es_super_admin' => true,
            ]
        );

        // Administrador de respaldo / pruebas.
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

        // En BD nueva los seeders corren después de las migraciones: el backfill
        // crea aquí las empresas de los usuarios sembrados (es idempotente).
        \App\Business\Application\BackfillEmpresas::run();
    }
}
