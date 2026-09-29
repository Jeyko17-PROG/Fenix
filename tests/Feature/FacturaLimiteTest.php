<?php

namespace Tests\Feature;

use App\Billing\Infrastructure\Persistence\Eloquent\Factura;
use App\Business\Infrastructure\Persistence\Eloquent\Bodega;
use App\IAM\Infrastructure\Persistence\Eloquent\Plan;
use App\IAM\Infrastructure\Persistence\Eloquent\Role;
use App\IAM\Infrastructure\Persistence\Eloquent\User;
use App\Operations\Infrastructure\Persistence\Eloquent\Cliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre el límite de facturas por plan: las cotizaciones (BORRADOR) no deben
 * contar, el límite debe bloquear justo al alcanzarse, y eliminar una factura
 * debe liberar cupo (sin que el conteo "se quede pegado" por encima del límite).
 */
class FacturaLimiteTest extends TestCase
{
    use RefreshDatabase;

    private function crearUsuarioConLimite(int $limite): User
    {
        $role = Role::create(['nombre' => 'Administrador', 'descripcion' => 'Administrador']);
        // El feature "facturacion" solo está activo si el plan lo incluye
        // (ver Funcionalidades::respaldoPorPlan) - el límite real de la
        // prueba lo pone el override en el usuario (limite_facturas), que
        // siempre gana sobre el del plan.
        $plan = Plan::create([
            'nombre' => 'PlanPrueba-' . uniqid(),
            'precio_mensual' => 0,
            'limite_clientes' => 1000,
            'limite_citas' => 1000,
            'limite_facturas' => 1000,
            'funcionalidades' => ['facturacion'],
            'activo' => true,
            'orden' => 0,
        ]);

        $user = User::factory()->create([
            'rol_id' => $role->id,
            'plan_id' => $plan->id,
            'email' => 'dueno-' . uniqid() . '@example.com',
            'name' => 'Dueño',
            'estado' => 'ACTIVO',
            'activo' => true,
            'limite_facturas' => $limite,
        ]);

        Bodega::create([
            'owner_id' => $user->id,
            'nombre' => 'Principal',
            'direccion' => 'Calle 1',
            'responsable_id' => $user->id,
            'activo' => true,
            'es_principal' => true,
        ]);

        return $user;
    }

    private function payloadFactura(int $clienteId, bool $esCotizacion = false): array
    {
        return [
            'cliente_id' => $clienteId,
            'fecha' => now()->toDateString(),
            'lineas' => [
                ['descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => 10000],
            ],
            'es_cotizacion' => $esCotizacion,
        ];
    }

    public function test_bloquea_al_alcanzar_el_limite_del_plan_y_no_deja_pasar_de_largo(): void
    {
        $user = $this->crearUsuarioConLimite(20);
        $cliente = Cliente::create(['owner_id' => $user->id, 'nombre_completo' => 'Cliente Uno']);

        $this->actingAs($user, 'sanctum');

        for ($i = 1; $i <= 20; $i++) {
            $this->postJson('/api/facturas', $this->payloadFactura($cliente->id))
                ->assertStatus(201);
        }

        $this->assertSame(20, Factura::count());
        $this->assertSame(20, $user->fresh()->facturasUsadas());

        // La factura número 21 debe bloquearse (no debe "seguir contando" de largo).
        $this->postJson('/api/facturas', $this->payloadFactura($cliente->id))
            ->assertStatus(403)
            ->assertJsonPath('limite_alcanzado', true)
            ->assertJsonPath('limite', 20)
            ->assertJsonPath('usados', 20);

        $this->assertSame(20, Factura::count());
    }

    public function test_las_cotizaciones_no_cuentan_para_el_limite_de_facturas(): void
    {
        $user = $this->crearUsuarioConLimite(1);
        $cliente = Cliente::create(['owner_id' => $user->id, 'nombre_completo' => 'Cliente Dos']);

        $this->actingAs($user, 'sanctum');

        // Con límite de 1, deberían poder crearse muchas cotizaciones: no son ventas reales.
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/facturas', $this->payloadFactura($cliente->id, esCotizacion: true))
                ->assertStatus(201);
        }
        $this->assertSame(5, Factura::where('estado', 'BORRADOR')->count());
        $this->assertSame(0, $user->fresh()->facturasUsadas());

        // La única factura REAL sí cuenta y agota el único cupo disponible.
        $this->postJson('/api/facturas', $this->payloadFactura($cliente->id))->assertStatus(201);
        $this->postJson('/api/facturas', $this->payloadFactura($cliente->id))
            ->assertStatus(403)
            ->assertJsonPath('limite_alcanzado', true);
    }

    public function test_confirmar_una_cotizacion_con_el_primer_abono_respeta_el_limite(): void
    {
        $user = $this->crearUsuarioConLimite(1);
        $cliente = Cliente::create(['owner_id' => $user->id, 'nombre_completo' => 'Cliente Tres']);

        $this->actingAs($user, 'sanctum');

        // Agota el único cupo con una factura real.
        $this->postJson('/api/facturas', $this->payloadFactura($cliente->id))->assertStatus(201);

        // Una cotización sí puede crearse (no cuenta), pero no puede CONFIRMARSE
        // (primer abono) porque eso la convertiría en la factura real #2, que
        // ya no cabe en el cupo.
        $cotizacion = $this->postJson('/api/facturas', $this->payloadFactura($cliente->id, esCotizacion: true))
            ->assertStatus(201)->json();

        $this->postJson("/api/facturas/{$cotizacion['id']}/pagos", ['monto' => 10000])
            ->assertStatus(403)
            ->assertJsonPath('limite_alcanzado', true);

        $this->assertSame('BORRADOR', Factura::find($cotizacion['id'])->estado);
    }

    public function test_eliminar_una_factura_libera_cupo_y_no_vuelve_a_contar(): void
    {
        $user = $this->crearUsuarioConLimite(1);
        $cliente = Cliente::create(['owner_id' => $user->id, 'nombre_completo' => 'Cliente Cuatro']);

        $this->actingAs($user, 'sanctum');

        $factura = $this->postJson('/api/facturas', $this->payloadFactura($cliente->id))
            ->assertStatus(201)->json();

        $this->postJson('/api/facturas', $this->payloadFactura($cliente->id))->assertStatus(403);

        $this->deleteJson("/api/facturas/{$factura['id']}")->assertStatus(200);
        $this->assertSame(0, $user->fresh()->facturasUsadas());

        // El cupo liberado permite crear una nueva factura real.
        $this->postJson('/api/facturas', $this->payloadFactura($cliente->id))->assertStatus(201);
        $this->assertSame(1, $user->fresh()->facturasUsadas());
    }

    /**
     * Cuando el super-admin le cambia el plan a una cuenta, el límite de
     * facturas debe actualizarse solo (sin tocar ni una fila de "facturas"),
     * tanto si el plan nuevo tiene más cupo como si tiene menos que el que ya
     * llevaba usado.
     */
    public function test_cambiar_el_plan_actualiza_el_limite_sin_borrar_facturas_existentes(): void
    {
        $superAdmin = User::factory()->create(['es_super_admin' => true, 'estado' => 'ACTIVO', 'activo' => true]);

        $planAlto = Plan::create(['nombre' => 'Alto-' . uniqid(), 'precio_mensual' => 0, 'limite_clientes' => 1000, 'limite_citas' => 1000, 'limite_facturas' => 10, 'funcionalidades' => ['facturacion'], 'activo' => true, 'orden' => 0]);
        $planBajo = Plan::create(['nombre' => 'Bajo-' . uniqid(), 'precio_mensual' => 0, 'limite_clientes' => 1000, 'limite_citas' => 1000, 'limite_facturas' => 3, 'funcionalidades' => ['facturacion'], 'activo' => true, 'orden' => 1]);

        $role = Role::create(['nombre' => 'Administrador', 'descripcion' => 'Administrador']);
        $user = User::factory()->create(['rol_id' => $role->id, 'plan_id' => $planAlto->id, 'estado' => 'ACTIVO', 'activo' => true]);
        Bodega::create(['owner_id' => $user->id, 'nombre' => 'Principal', 'direccion' => 'Calle 1', 'responsable_id' => $user->id, 'activo' => true, 'es_principal' => true]);
        $cliente = Cliente::create(['owner_id' => $user->id, 'nombre_completo' => 'Cliente Cinco']);

        // Con el plan "Alto" (10) factura 5 veces - queda bien por debajo del límite.
        $this->actingAs($user, 'sanctum');
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/facturas', $this->payloadFactura($cliente->id))->assertStatus(201);
        }
        $this->assertSame(5, Factura::count());

        // El super-admin lo baja al plan "Bajo" (3) - ya usó más de lo que ese plan permite.
        $this->actingAs($superAdmin, 'sanctum');
        $this->postJson("/api/admin/usuarios/{$user->id}/plan", ['plan_id' => $planBajo->id])->assertStatus(200);

        // Las 5 facturas ya hechas siguen intactas - cambiar de plan nunca borra nada.
        $this->assertSame(5, Factura::count());
        $this->assertSame(3, $user->fresh()->limiteFacturasEfectivo());
        $this->assertSame(5, $user->fresh()->facturasUsadas());

        // Como ya está por encima del nuevo límite, no puede facturar una más.
        // OJO: actingAs() reutiliza la instancia de PHP tal cual - si no se
        // refresca aquí, seguiría teniendo el plan_id viejo en memoria aunque
        // la fila en la BD ya haya cambiado.
        $this->actingAs($user->fresh(), 'sanctum');
        $this->postJson('/api/facturas', $this->payloadFactura($cliente->id))
            ->assertStatus(403)
            ->assertJsonPath('limite_alcanzado', true)
            ->assertJsonPath('limite', 3)
            ->assertJsonPath('usados', 5);
        $this->assertSame(5, Factura::count()); // tampoco se creó nada a medias.

        // Si el super-admin lo vuelve a subir al plan "Alto" (10), puede seguir facturando.
        $this->actingAs($superAdmin, 'sanctum');
        $this->postJson("/api/admin/usuarios/{$user->id}/plan", ['plan_id' => $planAlto->id])->assertStatus(200);

        $this->actingAs($user, 'sanctum');
        $this->postJson('/api/facturas', $this->payloadFactura($cliente->id))->assertStatus(201);
        $this->assertSame(6, Factura::count());
    }
}
