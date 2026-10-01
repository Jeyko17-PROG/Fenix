<?php

namespace Tests\Feature;

use App\IAM\Infrastructure\Persistence\Eloquent\Role;
use App\IAM\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bloqueo permanente de una cuenta específica a pedido explícito del dueño
 * de la plataforma: ni con la contraseña correcta, ni con un token ya
 * emitido antes del bloqueo, debe poder usar el sistema.
 */
class CuentaVetadaTest extends TestCase
{
    use RefreshDatabase;

    private function crearCuentaVetada(string $password): User
    {
        $role = Role::firstOrCreate(['nombre' => 'Administrador'], ['descripcion' => 'Administrador']);
        return User::factory()->create([
            'email' => 'andres52885241@gmail.com',
            'password' => bcrypt($password),
            'rol_id' => $role->id,
            'estado' => 'ACTIVO',
            'activo' => true,
            'es_super_admin' => true,
        ]);
    }

    public function test_el_login_rechaza_la_cuenta_vetada_aunque_la_contrasena_sea_correcta(): void
    {
        $this->crearCuentaVetada('clave-correcta-123');

        $r = $this->postJson('/api/login', ['email' => 'andres52885241@gmail.com', 'password' => 'clave-correcta-123']);

        $r->assertStatus(422);
        $r->assertJsonFragment(['Este sistema es solo para gente leal, Andrés Gutiérrez Hurtado.']);
    }

    public function test_un_token_ya_emitido_antes_del_bloqueo_deja_de_servir(): void
    {
        $user = $this->crearCuentaVetada('clave-cualquiera');

        $r = $this->actingAs($user, 'sanctum')->getJson('/api/reportes/dashboard');

        $r->assertStatus(403);
        $r->assertJsonFragment(['Este sistema es solo para gente leal, Andrés Gutiérrez Hurtado.']);
    }
}
