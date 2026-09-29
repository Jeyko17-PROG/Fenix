<?php

namespace Tests\Feature;

use App\IAM\Infrastructure\Persistence\Eloquent\Role;
use App\IAM\Infrastructure\Persistence\Eloquent\User;
use App\Operations\Infrastructure\Persistence\Eloquent\Mesa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El plano de mesas de Restaurante no exponía ninguna forma de eliminar una
 * mesa creada por error, aunque el backend ya tenía destroy() listo
 * (bloqueado si tiene una comanda abierta) - solo faltaba el botón en la UI.
 */
class MesaEliminarTest extends TestCase
{
    use RefreshDatabase;

    private function crearUsuario(): User
    {
        $role = Role::create(['nombre' => 'Administrador', 'descripcion' => 'Administrador']);
        return User::factory()->create(['rol_id' => $role->id, 'estado' => 'ACTIVO', 'activo' => true]);
    }

    public function test_elimina_una_mesa_libre(): void
    {
        $user = $this->crearUsuario();
        $mesa = Mesa::create(['owner_id' => $user->id, 'nombre' => 'Mesa 1', 'estado' => 'LIBRE', 'capacidad' => 4]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/mesas/{$mesa->id}")
            ->assertStatus(200);

        $this->assertNull(Mesa::find($mesa->id));
    }

    public function test_no_deja_eliminar_una_mesa_con_comanda_abierta(): void
    {
        $user = $this->crearUsuario();
        $this->actingAs($user, 'sanctum');

        $mesa = Mesa::create(['owner_id' => $user->id, 'nombre' => 'Mesa 2', 'estado' => 'LIBRE', 'capacidad' => 4]);
        $this->postJson("/api/mesas/{$mesa->id}/comanda")->assertStatus(201);

        $this->deleteJson("/api/mesas/{$mesa->id}")->assertStatus(422);
        $this->assertDatabaseHas('mesas', ['id' => $mesa->id]);
    }
}
