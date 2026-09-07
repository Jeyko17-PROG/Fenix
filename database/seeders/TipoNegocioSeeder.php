<?php

namespace Database\Seeders;

use App\Models\TipoNegocio;
use App\Support\Funcionalidades;
use Illuminate\Database\Seeder;

/**
 * Catálogo de tipos de negocio con sus módulos por defecto.
 *
 * IMPORTANTE: los modulos_default solo se establecen al CREAR el tipo.
 * Si el tipo ya existe, se respetan los módulos que haya definido el
 * super-admin desde su panel (este seeder corre en cada deploy).
 */
class TipoNegocioSeeder extends Seeder
{
    public function run(): void
    {
        // Módulos comunes a cualquier negocio con ventas.
        $comunes = [
            'dashboard', 'notificaciones', 'notas', 'calculadora',
            'clientes', 'facturacion', 'pdf', 'correos', 'exportacion', 'reportes', 'caja',
        ];

        // "Todos los módulos" excepto los exclusivos de restaurante, lavadero, barbería o tatuajes.
        $todosSinRestaurante = array_values(array_diff(array_keys(Funcionalidades::CATALOGO), ['mesas', 'cocina', 'lavadero', 'barberia', 'tatuajes']));

        $tipos = [
            ['clave' => 'taller_motos', 'nombre' => 'Taller de motos', 'orden' => 1,
                'modulos_default' => $todosSinRestaurante],
            ['clave' => 'taller_carros', 'nombre' => 'Taller de carros', 'orden' => 2,
                'modulos_default' => $todosSinRestaurante],
            ['clave' => 'taller_general', 'nombre' => 'Taller / servicio técnico general', 'orden' => 3,
                'modulos_default' => $todosSinRestaurante],
            ['clave' => 'lavadero', 'nombre' => 'Lavadero de vehículos', 'orden' => 4,
                'modulos_default' => array_merge($comunes, ['servicios', 'lavadero', 'agenda', 'reservas', 'qr', 'productos', 'inventario'])],
            ['clave' => 'tienda', 'nombre' => 'Tienda / comercio', 'orden' => 5,
                'modulos_default' => array_merge($comunes, ['productos', 'inventario', 'proveedores', 'documental', 'ocr', 'firma'])],
            ['clave' => 'restaurante', 'nombre' => 'Restaurante / comidas', 'orden' => 6,
                'modulos_default' => array_merge($comunes, ['mesas', 'cocina', 'productos', 'inventario', 'proveedores', 'agenda', 'reservas', 'qr'])],
            ['clave' => 'barberia', 'nombre' => 'Barbería / belleza', 'orden' => 7,
                'modulos_default' => array_merge($comunes, ['servicios', 'barberia', 'agenda', 'reservas', 'qr', 'productos', 'inventario'])],
            // Spa/estética: solo agenda de citas y portal de reservas (sin el POS de
            // órdenes con vehículo/comisiones que sí usan lavadero y barbería).
            ['clave' => 'spa', 'nombre' => 'Spa / Estética de belleza', 'orden' => 8,
                'modulos_default' => array_merge($comunes, ['agenda', 'reservas', 'qr', 'productos', 'inventario'])],
            // Estudio de tatuajes: mismo esquema que barbería (artistas = operables_employees,
            // agenda de citas con foto de referencia y zona/tamaño), más catálogo de productos
            // (cuidado post-tatuaje, merch) e inventario.
            ['clave' => 'tatuajes', 'nombre' => 'Estudio de tatuajes', 'orden' => 9,
                'modulos_default' => array_merge($comunes, ['servicios', 'tatuajes', 'agenda', 'reservas', 'qr', 'productos', 'inventario'])],
            // Venta de accesorios/repuestos de moto (catálogo + inventario propio) con
            // servicio de instalación agendable (ej. instalación de luces) a cargo de
            // un equipo de instaladores (operables_employees, rol "Instalador").
            ['clave' => 'accesorios_motos', 'nombre' => 'Accesorios y repuestos de motos', 'orden' => 10,
                'modulos_default' => array_merge($comunes, ['servicios', 'agenda', 'reservas', 'qr', 'productos', 'inventario', 'proveedores'])],
            ['clave' => 'otro', 'nombre' => 'Otro negocio', 'orden' => 99,
                'modulos_default' => $todosSinRestaurante],
        ];

        foreach ($tipos as $t) {
            $existente = TipoNegocio::where('clave', $t['clave'])->first();
            if ($existente) {
                // Solo refresca nombre/orden; respeta los módulos personalizados.
                $existente->update(['nombre' => $t['nombre'], 'orden' => $t['orden'], 'activo' => true]);
                // Excepción: si el tipo quedó con null (sin restricción) de versiones
                // anteriores, se materializa la lista para poder excluir restaurante.
                if ($existente->modulos_default === null) {
                    $existente->update(['modulos_default' => $t['modulos_default']]);
                }
            } else {
                TipoNegocio::create([
                    'clave' => $t['clave'],
                    'nombre' => $t['nombre'],
                    'orden' => $t['orden'],
                    'modulos_default' => $t['modulos_default'],
                    'activo' => true,
                ]);
            }
        }

        $this->agregarModulosNuevos();
    }

    /**
     * Módulos añadidos DESPUÉS del lanzamiento (ej. 'mesas'/'cocina' del módulo
     * restaurante) no pueden existir todavía en el modulos_default guardado de
     * instalaciones previas: ningún super-admin pudo haberlos quitado a propósito
     * porque no existían. Se agregan una sola vez por tipo+módulo (idempotente
     * vía Cache) sin tocar el resto de la personalización.
     */
    private function agregarModulosNuevos(): void
    {
        $nuevos = [
            'restaurante' => ['mesas', 'cocina'],
            'lavadero' => ['lavadero'],
            'barberia' => ['barberia'],
        ];

        foreach ($nuevos as $clave => $modulos) {
            $flag = "tipo_negocio_migrado_modulos_{$clave}";
            if (\Illuminate\Support\Facades\Cache::get($flag)) {
                continue;
            }

            $tipo = TipoNegocio::where('clave', $clave)->first();
            if ($tipo && is_array($tipo->modulos_default)) {
                $tipo->update(['modulos_default' => array_values(array_unique([...$tipo->modulos_default, ...$modulos]))]);
            }

            \Illuminate\Support\Facades\Cache::forever($flag, true);
        }
    }
}
