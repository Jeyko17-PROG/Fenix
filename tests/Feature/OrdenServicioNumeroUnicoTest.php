<?php

namespace Tests\Feature;

use App\IAM\Infrastructure\Persistence\Eloquent\Role;
use App\IAM\Infrastructure\Persistence\Eloquent\User;
use App\Operations\Infrastructure\Persistence\Eloquent\Cliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * numero_orden se generaba contando solo las órdenes del owner ("SO-fecha-00001"
 * para la primera orden de CUALQUIER empresa), pero la columna era UNIQUE a
 * nivel global en la base de datos: la primera orden del día de una segunda
 * empresa fallaba con un error 500 al chocar con la primera empresa. Ver
 * migración 2026_09_29_100000_numero_orden_unico_por_empresa.
 */
class OrdenServicioNumeroUnicoTest extends TestCase
{
    use RefreshDatabase;

    private function crearUsuarioConCliente(): array
    {
        $role = Role::firstOrCreate(['nombre' => 'Administrador'], ['descripcion' => 'Administrador']);
        $user = User::factory()->create(['rol_id' => $role->id, 'estado' => 'ACTIVO', 'activo' => true]);
        $cliente = Cliente::create(['owner_id' => $user->id, 'nombre_completo' => 'Cliente ' . $user->id]);
        return [$user, $cliente];
    }

    public function test_dos_empresas_distintas_pueden_crear_su_primera_orden_el_mismo_dia(): void
    {
        [$userA, $clienteA] = $this->crearUsuarioConCliente();
        [$userB, $clienteB] = $this->crearUsuarioConCliente();

        $ordenA = $this->actingAs($userA, 'sanctum')
            ->postJson('/api/ordenes-servicio', ['cliente_id' => $clienteA->id])
            ->assertStatus(201)->json();

        $ordenB = $this->actingAs($userB, 'sanctum')
            ->postJson('/api/ordenes-servicio', ['cliente_id' => $clienteB->id])
            ->assertStatus(201)->json();

        // Ambas son legítimamente "la primera orden del día" de su propia empresa:
        // mismo número de exhibición, pero pertenecen a owners distintos.
        $this->assertSame($ordenA['numero_orden'], $ordenB['numero_orden']);
        $this->assertNotEquals($ordenA['owner_id'], $ordenB['owner_id']);
    }
}
