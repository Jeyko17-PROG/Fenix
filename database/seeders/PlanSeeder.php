<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Support\Funcionalidades;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        // Funcionalidades por nivel (acumulativas), según el comparador comercial.
        $gratuito = ['dashboard', 'clientes', 'agenda', 'reservas', 'qr', 'notas', 'calculadora', 'notificaciones'];
        $normal = array_merge($gratuito, ['facturacion', 'proveedores', 'productos', 'documental', 'pdf', 'correos']);
        $medio = array_merge($normal, ['inventario', 'reportes', 'exportacion', 'firma']);
        $premium = array_keys(Funcionalidades::CATALOGO); // acceso total (incluye OCR)

        $planes = [
            [
                'nombre' => 'Gratuito',
                'precio_mensual' => 0,
                'limite_clientes' => 200,
                'limite_citas' => 100,
                'orden' => 0,
                'funcionalidades' => $gratuito,
                'incluye' => ['Dashboard básico', 'Clientes', 'Agenda y citas', 'Portal de reservas', 'Código QR', 'Bloc de notas', 'Calculadora'],
            ],
            [
                'nombre' => 'Normal',
                'precio_mensual' => 90000,
                'limite_clientes' => 500,
                'limite_citas' => 1000,
                'orden' => 1,
                'funcionalidades' => $normal,
                'incluye' => ['Todo lo del plan Gratuito', 'Facturación electrónica', 'Proveedores', 'Productos', 'Gestión documental básica', 'Exportación PDF', 'Notificaciones por correo'],
            ],
            [
                'nombre' => 'Medio',
                'precio_mensual' => 160000,
                'limite_clientes' => 1000,
                'limite_citas' => 5000,
                'orden' => 2,
                'funcionalidades' => $medio,
                'incluye' => ['Todo lo del plan Normal', 'Inventario completo', 'Gestión de bodegas', 'Reportes avanzados', 'Alertas de stock', 'Firma digital', 'Gestión documental avanzada'],
            ],
            [
                'nombre' => 'Premium',
                'precio_mensual' => 250000,
                'limite_clientes' => 5000,
                'limite_citas' => 100000,
                'orden' => 3,
                'funcionalidades' => $premium,
                'incluye' => ['Acceso completo a toda la plataforma', 'OCR de documentos', 'Firma digital', 'Reportes y estadísticas avanzadas', 'Funciones premium futuras', 'Acceso prioritario a nuevas actualizaciones'],
            ],
        ];

        // IMPORTANTE: estos valores solo se aplican al CREAR el plan (primera
        // vez, instalación nueva). Si el plan ya existe, se respeta lo que el
        // super-admin haya editado desde /planes (precio, límites, funcio-
        // nalidades, etc.) — este seeder corre en CADA deploy (db:seed
        // --force en docker/start.sh), así que un updateOrCreate aquí
        // resetearía silenciosamente cualquier edición manual cada vez que
        // se sube un cambio nuevo (mismo problema que ya se evitó en
        // TipoNegocioSeeder).
        foreach ($planes as $p) {
            Plan::firstOrCreate(['nombre' => $p['nombre']], $p);
        }
    }
}
