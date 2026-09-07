<?php

namespace Database\Seeders;

use App\Models\Cita;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Plan;
use App\Models\Role;
use App\Models\TipoNegocio;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Cuenta de prueba para validar en UI/UX el bloqueo por límite de citas del plan
 * Gratuito (100). A propósito NO está en DatabaseSeeder: se corre a mano cuando
 * haga falta, para no crear datos falsos en cada despliegue de producción.
 *
 *   php artisan db:seed --class=CuentaPruebaCitasSeeder
 *
 * Idempotente: se puede correr varias veces sin duplicar la cuenta ni pasar de 100 citas.
 */
class CuentaPruebaCitasSeeder extends Seeder
{
    public function run(): void
    {
        $planGratuito = Plan::where('nombre', 'Gratuito')->firstOrFail();
        $rolAdmin = Role::where('nombre', 'Administrador')->first() ?? Role::where('nombre', 'Usuario')->firstOrFail();
        $tipoNegocio = TipoNegocio::where('clave', 'spa')->first();

        $user = User::updateOrCreate(
            ['email' => 'ejemploprueba@gmail.com'],
            [
                'name' => 'Cuenta de prueba (límite de citas)',
                'password' => Hash::make('prueba1234'),
                'rol_id' => $rolAdmin->id,
                'plan_id' => $planGratuito->id,
                'es_admin_empresa' => true,
                'activo' => true,
                'estado' => 'ACTIVO',
            ]
        );

        $empresa = Empresa::updateOrCreate(
            ['owner_user_id' => $user->id],
            [
                'nombre' => 'Negocio de prueba (límite citas)',
                'tipo_negocio_id' => $tipoNegocio?->id,
                'plan_id' => $planGratuito->id,
                'modo_cobro' => 'membresia',
                'membresia_vence_at' => now()->addYear(),
                'estado' => 'ACTIVO',
                'activo' => true,
            ]
        );

        if (empty($user->empresa_id)) {
            $user->update(['empresa_id' => $empresa->id]);
        }

        $cliente = Cliente::updateOrCreate(
            ['owner_id' => $user->id, 'email' => 'cliente.prueba@gmail.com'],
            [
                'empresa_id' => $empresa->id,
                'nombre_completo' => 'Cliente de prueba',
                'estado' => 'ACTIVO',
                'created_by' => $user->id,
            ]
        );

        $existentes = Cita::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count();
        $faltan = max(0, 100 - $existentes);

        for ($i = 0; $i < $faltan; $i++) {
            $inicio = Carbon::now()->subDays(100 - $existentes - $i)->setTime(10, 0);
            Cita::create([
                'owner_id' => $user->id,
                'empresa_id' => $empresa->id,
                'cliente_id' => $cliente->id,
                'inicio' => $inicio,
                'fin' => $inicio->copy()->addMinutes(30),
                'estado' => 'COMPLETADA',
                'origen' => 'ADMIN',
                'created_by' => $user->id,
            ]);
        }

        $total = Cita::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count();
        $this->command?->info("Cuenta de prueba lista: ejemploprueba@gmail.com (password: prueba1234) — {$total} citas, plan Gratuito (límite 100).");
    }
}
