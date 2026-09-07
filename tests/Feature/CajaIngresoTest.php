<?php

namespace Tests\Feature;

use App\Models\Bodega;
use App\Models\Factura;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CajaIngresoTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_register_cash_income_and_it_increases_daily_profit(): void
    {
        $role = Role::create(['nombre' => 'Administrador', 'descripcion' => 'Administrador']);
        $user = User::factory()->create([
            'rol_id' => $role->id,
            'email' => 'caja@example.com',
            'name' => 'Cajero',
            'estado' => 'ACTIVO',
            'activo' => true,
        ]);
        Bodega::create([
            'owner_id' => $user->id,
            'nombre' => 'Principal',
            'direccion' => 'Calle 1',
            'responsable_id' => $user->id,
            'activo' => true,
            'es_principal' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/caja/ingresos', [
            'descripcion' => 'Ingreso por préstamo',
            'monto' => 125000,
            'nombre_cliente' => 'Carlos Pérez',
            'cedula' => '123456789',
            'fecha' => now()->toDateString(),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('detalles.0.descripcion', 'Ingreso por préstamo')
            ->assertJsonPath('total', '125000.00');

        $this->assertDatabaseHas('clientes', ['nombre_completo' => 'Carlos Pérez', 'numero_documento' => '123456789']);
        $this->assertDatabaseHas('facturas', ['notas' => 'Ingreso de caja: Ingreso por préstamo']);

        $utilidad = $this->actingAs($user, 'sanctum')->getJson('/api/reportes/utilidad-dia?fecha=' . now()->toDateString());
        $utilidad->assertStatus(200)
            ->assertJsonPath('ventas', 125000);
        $this->assertSame(125000.0, (float) Factura::latest()->first()->total);
    }
}
