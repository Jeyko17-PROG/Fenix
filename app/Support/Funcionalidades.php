<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserFuncionalidad;

/**
 * Catálogo de funcionalidades (módulos) de la plataforma y resolución
 * del estado efectivo por usuario (Control de Funcionalidades).
 *
 * Estados: ACTIVADA (uso normal), RESTRINGIDA (ve pero no usa), DESACTIVADA (no aparece).
 */
class Funcionalidades
{
    /** Catálogo: clave => etiqueta. */
    public const CATALOGO = [
        'clientes' => 'Clientes',
        'agenda' => 'Agenda y Citas',
        'reservas' => 'Portal de Reservas',
        'qr' => 'Código QR',
        'facturacion' => 'Facturación Electrónica',
        'firma' => 'Firma Digital',
        'proveedores' => 'Proveedores',
        'inventario' => 'Inventario y Bodegas',
        'productos' => 'Productos',
        'documental' => 'Gestión Documental',
        'ocr' => 'OCR de documentos',
        'dashboard' => 'Dashboard',
        'reportes' => 'Reportes',
        'notificaciones' => 'Notificaciones',
        'notas' => 'Bloc de Notas',
        'calculadora' => 'Calculadora',
        'exportacion' => 'Exportación de datos',
        'pdf' => 'Descarga de PDF',
        'correos' => 'Envío de correos',
        'servicios' => 'Taller / Órdenes de Servicio',
        'caja' => 'Caja y Gastos',
        'mesas' => 'Restaurante: Mesas y Comandas',
        'cocina' => 'Restaurante: Pantalla de Cocina (KDS)',
        'lavadero' => 'Lavadero / Servicios de Lavado',
        'barberia' => 'Barbería / Agenda y Estilistas',
        'tatuajes' => 'Estudio de Tatuajes / Artistas',
    ];

    public const ESTADOS = ['ACTIVADA', 'RESTRINGIDA', 'DESACTIVADA'];

    /**
     * Funcionalidades base disponibles en cualquier plan (incluido el Gratuito).
     * 'servicios' y 'caja' van en la base porque son el corazón del POS de taller
     * (además, los planes ya guardados en BD no las incluyen y quedarían bloqueadas).
     */
    // 'mesas'/'cocina' (restaurante), 'lavadero', 'barberia' y 'tatuajes' también van en BASE: el
    // TIPO DE NEGOCIO es quien las limita (solo los tipos que las incluyen en modulos_default las ven).
    private const BASE = ['dashboard', 'notificaciones', 'notas', 'calculadora', 'servicios', 'caja', 'mesas', 'cocina', 'lavadero', 'barberia', 'tatuajes'];

    /**
     * Funcionalidades ACTIVADAS por defecto según el plan.
     * Primero lee las definidas por el super-admin en la BD; si el plan no las
     * tiene definidas, usa el mapeo por defecto (respaldo en código).
     */
    public static function permitidasPorPlan(?string $plan): array
    {
        static $cache = [];
        $clave = $plan ?? '__none__';
        if (isset($cache[$clave])) {
            return $cache[$clave];
        }

        $definidas = $plan ? \App\Models\Plan::where('nombre', $plan)->value('funcionalidades') : null;
        if (is_array($definidas)) {
            return $cache[$clave] = array_values(array_unique(array_merge(self::BASE, $definidas)));
        }

        return $cache[$clave] = self::respaldoPorPlan($plan);
    }

    /** Mapeo por defecto si la BD aún no define funcionalidades para el plan. */
    private static function respaldoPorPlan(?string $plan): array
    {
        $gratuito = array_merge(self::BASE, ['clientes', 'agenda', 'reservas', 'qr']);
        $normal = array_merge($gratuito, ['facturacion', 'pdf', 'correos', 'proveedores', 'productos', 'documental']);
        $medio = array_merge($normal, ['inventario', 'reportes', 'exportacion', 'firma']);
        $premium = array_keys(self::CATALOGO); // acceso total

        return match ($plan) {
            'Normal' => $normal,
            'Medio' => $medio,
            'Premium' => $premium,
            default => $gratuito, // Gratuito o sin plan
        };
    }

    /** Estado por defecto (según plan) de una funcionalidad. */
    public static function estadoPorPlan(?string $plan, string $clave): string
    {
        return in_array($clave, self::permitidasPorPlan($plan), true) ? 'ACTIVADA' : 'DESACTIVADA';
    }

    /**
     * Estado efectivo de una funcionalidad para un usuario (multiempresa):
     *  1. Super-admin: todo ACTIVADA.
     *  2. El TIPO DE NEGOCIO de la empresa limita el universo de módulos
     *     (modulos_default; null = sin restricción).
     *  3. Override del super-admin para la empresa (empresa_modulos).
     *  4. Transición: override antiguo por usuario dueño (user_funcionalidades).
     *  5. Default según el plan de la empresa.
     */
    public static function estadoEfectivo(User $user, string $clave): string
    {
        if ($user->esSuperAdmin()) {
            return 'ACTIVADA';
        }

        $empresa = $user->empresaDeCobro();
        if ($empresa) {
            $defaults = $empresa->tipoNegocio?->modulos_default;
            if (is_array($defaults) && ! in_array($clave, $defaults, true)) {
                return 'DESACTIVADA';
            }

            $override = \App\Models\EmpresaModulo::where('empresa_id', $empresa->id)
                ->whereHas('modulo', fn ($q) => $q->where('clave', $clave))
                ->value('estado');
            if ($override) {
                return $override;
            }

            $overrideLegado = UserFuncionalidad::where('user_id', $empresa->owner_user_id)
                ->where('clave', $clave)->value('estado');
            if ($overrideLegado) {
                return $overrideLegado;
            }

            return self::estadoPorPlan($empresa->plan?->nombre, $clave);
        }

        // Usuario sin empresa (previo al backfill): lógica anterior.
        $override = UserFuncionalidad::where('user_id', $user->id)->where('clave', $clave)->value('estado');
        if ($override) {
            return $override;
        }
        return self::estadoPorPlan($user->plan?->nombre, $clave);
    }

    /** Mapa completo clave => estado efectivo para un usuario. */
    public static function mapaEfectivo(User $user): array
    {
        $mapa = [];
        foreach (array_keys(self::CATALOGO) as $clave) {
            $mapa[$clave] = self::estadoEfectivo($user, $clave);
        }
        return $mapa;
    }

    /**
     * Estado efectivo por EMPRESA (para el panel del super-admin):
     * misma cadena que estadoEfectivo pero sin usuario de por medio.
     */
    public static function estadoEfectivoEmpresa(\App\Models\Empresa $empresa, string $clave): string
    {
        $defaults = $empresa->tipoNegocio?->modulos_default;
        if (is_array($defaults) && ! in_array($clave, $defaults, true)) {
            return 'DESACTIVADA';
        }

        $override = \App\Models\EmpresaModulo::where('empresa_id', $empresa->id)
            ->whereHas('modulo', fn ($q) => $q->where('clave', $clave))
            ->value('estado');
        if ($override) {
            return $override;
        }

        $overrideLegado = UserFuncionalidad::where('user_id', $empresa->owner_user_id)
            ->where('clave', $clave)->value('estado');
        if ($overrideLegado) {
            return $overrideLegado;
        }

        return self::estadoPorPlan($empresa->plan?->nombre, $clave);
    }
}
