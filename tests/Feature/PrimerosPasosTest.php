<?php

namespace Tests\Feature;

use App\Billing\Infrastructure\Persistence\Eloquent\Factura;
use App\Business\Infrastructure\Persistence\Eloquent\TipoNegocio;
use App\IAM\Infrastructure\Persistence\Eloquent\Plan;
use App\IAM\Infrastructure\Persistence\Eloquent\User;
use App\Operations\Infrastructure\Persistence\Eloquent\Cliente;
use App\Operations\Infrastructure\Persistence\Eloquent\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El checklist de "primeros pasos" del dashboard debe reflejar lo que la
 * cuenta ya hizo de verdad (producto real, cliente real, venta real), no
 * solo si existen filas en la base — una cuenta recién creada ya trae de
 * fábrica una bodega y el cliente genérico "Consumidor Final", así que ese
 * cliente NO debe contar como "ya agregaste tu primer cliente". Además, cada
 * paso solo debe aparecer si el plan de la cuenta incluye ese módulo (si no,
 * el botón "Ir a Facturación" llevaría a un 403 de funcionalidad bloqueada).
 */
class PrimerosPasosTest extends TestCase
{
    use RefreshDatabase;

    private function registrarYActivar(?string $planNombre = null): User
    {
        $tipo = TipoNegocio::firstOrCreate(['clave' => 'otro'], ['nombre' => 'Otro', 'activo' => true, 'orden' => 0]);
        $email = 'nueva-' . uniqid() . '@gmail.com';

        $this->postJson('/api/register', [
            'name' => 'Carlos Ruiz',
            'email' => $email,
            'password' => 'clave12345',
            'password_confirmation' => 'clave12345',
            'nombre_empresa' => 'Taller de Carlos',
            'tipo_negocio_id' => $tipo->id,
            'tipo_negocio_otro' => 'Negocio de prueba',
            'tipo_documento' => 'CC',
            'numero_documento' => '1020304050',
        ])->assertStatus(201);

        $user = User::where('email', $email)->firstOrFail();
        $user->activarPendiente();

        if ($planNombre) {
            $plan = Plan::firstOrCreate(
                ['nombre' => $planNombre],
                ['precio_mensual' => 0, 'limite_clientes' => 1000, 'limite_citas' => 1000, 'limite_facturas' => 1000,
                    'funcionalidades' => ['dashboard', 'clientes', 'productos', 'facturacion'], 'activo' => true, 'orden' => 1]
            );
            $user->update(['plan_id' => $plan->id]);
            $user->empresaDeCobro()?->update(['plan_id' => $plan->id]);
        }

        return $user->fresh();
    }

    public function test_el_plan_gratuito_solo_muestra_los_pasos_de_modulos_incluidos(): void
    {
        $user = $this->registrarYActivar(); // Gratuito: no incluye productos ni facturación.

        // La cuenta nueva ya trae "Consumidor Final" de fábrica - eso NO cuenta como cliente real.
        $this->assertTrue(Cliente::where('nombre_completo', 'Consumidor Final')->exists());

        $r = $this->actingAs($user, 'sanctum')->getJson('/api/reportes/dashboard');
        $r->assertStatus(200);

        $pasos = $r->json('primeros_pasos');
        $this->assertFalse($pasos['completo']);
        // Solo "negocio" y "cliente": el plan Gratuito no incluye productos ni facturación.
        $claves = collect($pasos['pasos'])->pluck('clave')->all();
        $this->assertSame(['negocio', 'cliente'], $claves);
        foreach ($pasos['pasos'] as $paso) {
            $this->assertFalse($paso['listo'], "El paso '{$paso['clave']}' no debería estar listo todavía.");
        }
    }

    public function test_el_checklist_se_va_completando_a_medida_que_la_cuenta_avanza(): void
    {
        $user = $this->registrarYActivar('Normal-Test'); // incluye productos y facturación.

        Producto::create(['owner_id' => $user->id, 'empresa_id' => $user->empresa_id, 'nombre' => 'Cambio de aceite', 'sku' => 'SKU-1', 'precio_venta' => 50000, 'activo' => true]);
        Cliente::create(['owner_id' => $user->id, 'empresa_id' => $user->empresa_id, 'nombre_completo' => 'María López', 'estado' => 'ACTIVO']);

        $r = $this->actingAs($user, 'sanctum')->getJson('/api/reportes/dashboard');
        $pasos = collect($r->json('primeros_pasos.pasos'))->keyBy('clave');

        $this->assertCount(4, $pasos);
        $this->assertTrue($pasos['producto']['listo']);
        $this->assertTrue($pasos['cliente']['listo']);
        $this->assertFalse($pasos['factura']['listo']);
        $this->assertFalse($pasos['negocio']['listo']);
        $this->assertFalse($r->json('primeros_pasos.completo'));
    }
}
