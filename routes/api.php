<?php

use App\Http\Controllers\AdjuntoController;
use App\Http\Controllers\AssetVehicleController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BodegaController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\CitaController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ConfiguracionAgendaController;
use App\Http\Controllers\CuentaController;
use App\Http\Controllers\GaleriaController;
use App\Http\Controllers\DocumentoController;
use App\Http\Controllers\ExtraccionController;
use App\Http\Controllers\FacturaController;
use App\Http\Controllers\MetodoPagoCobroController;
use App\Http\Controllers\FeatureController;
use App\Http\Controllers\FirmaController;
use App\Http\Controllers\NotaController;
use App\Http\Controllers\NotificacionController;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\OperablesEmployeeController;
use App\Http\Controllers\OrdenCompraController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProveedorController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\ServicioController;
use App\Http\Controllers\PlanLavadoController;
use App\Http\Controllers\ServiceOrderController;
use App\Http\Controllers\UsuarioAdminController;
use Illuminate\Support\Facades\Route;

// --- Salud / prueba de conexión ---
Route::get('/ping', function () {
    // Diagnóstico de configuración: solo booleanos/conteos, nunca secretos.
    $migracionesPendientes = null;
    try {
        $corridas = \Illuminate\Support\Facades\DB::table('migrations')->pluck('migration');
        $archivos = collect(glob(database_path('migrations/*.php')))->map(fn ($f) => basename($f, '.php'));
        $migracionesPendientes = $archivos->diff($corridas)->count();
    } catch (\Throwable $e) {
        // sin acceso a BD: se reporta null
    }

    // Verificación del comercio en Wompi (cacheada 10 min, solo booleanos).
    $wompi = app(\App\Services\WompiService::class);
    $comercio = $wompi->configurado() ? $wompi->verificarComercio() : null;

    // Cola de correos: si "pendientes" crece y nunca baja, el worker no está corriendo.
    $cola = null;
    try {
        $masAntiguo = \Illuminate\Support\Facades\DB::table('jobs')->min('created_at');
        // Primera línea del último fallo (sin trazas), para diagnosticar envíos.
        $ultimoFallo = \Illuminate\Support\Facades\DB::table('failed_jobs')->orderByDesc('id')->value('exception');
        $cola = [
            'pendientes' => \Illuminate\Support\Facades\DB::table('jobs')->count(),
            'fallidos' => \Illuminate\Support\Facades\DB::table('failed_jobs')->count(),
            'mas_antiguo_seg' => $masAntiguo ? max(0, now()->timestamp - (int) $masAntiguo) : 0,
            'ultimo_fallo' => $ultimoFallo ? \Illuminate\Support\Str::limit(strtok($ultimoFallo, "\n"), 220) : null,
        ];
    } catch (\Throwable $e) {
        // sin acceso a BD
    }

    return response()->json([
        'message' => 'conectado',
        'app' => config('app.name'),
        'time' => now()->toIso8601String(),
        'diagnostico' => [
            'correo_smtp_configurado' => in_array(config('mail.default'), ['gmail', 'brevo'], true)
                || (config('mail.default') === 'smtp' && ! empty(config('mail.mailers.smtp.username'))),
            'correo_smtp_destino' => match (config('mail.default')) {
                'gmail' => 'gmail.googleapis.com (HTTP)',
                'brevo' => 'api.brevo.com (HTTP)',
                default => config('mail.mailers.smtp.host') . ':' . config('mail.mailers.smtp.port'),
            },
            'gmail_api' => [
                'credenciales' => ! empty(config('services.gmail.client_id')) && ! empty(config('services.gmail.client_secret')),
                'autorizado' => \App\Mail\Transport\GmailApiTransport::configurado(),
            ],
            'wompi_configurada' => $wompi->configurado() && ! empty(config('services.wompi.integrity_secret')),
            'wompi_comercio_valido' => $comercio['ok'] ?? null,
            'wompi_ambiente' => $wompi->configurado() ? ($wompi->esSandbox() ? 'sandbox' : 'produccion') : null,
            'wompi_detalle' => ($comercio['ok'] ?? true) ? null : ($comercio['error'] ?? null),
            'wompi_firma_integridad' => $wompi->configurado() ? ($wompi->validarIntegridad() ?? 'OK') : null,
            'cola_correos' => $cola,
            'migraciones_pendientes' => $migracionesPendientes,
        ],
    ]);
});

// --- Autenticación (público) ---
// Catálogo de tipos de negocio para el formulario de registro.
Route::get('/tipos-negocio', function () {
    return \App\Models\TipoNegocio::where('activo', true)
        ->orderBy('orden')
        ->get(['id', 'clave', 'nombre', 'descripcion']);
});
Route::post('/register', [AuthController::class, 'register']);
Route::post('/activar', [AuthController::class, 'activar']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// --- BLOQUE C: Portal público de reservas (sin autenticación, destino del QR) ---
// Versión POR USUARIO: cada negocio tiene su slug único en la URL.
Route::prefix('publico/{slug}')->group(function () {
    Route::get('negocio', [PortalController::class, 'negocio']);
    Route::get('sucursales', [PortalController::class, 'sucursales']);
    Route::get('servicios', [PortalController::class, 'servicios']);
    Route::get('productos', [PortalController::class, 'productos']);
    Route::get('planes-lavado', [PortalController::class, 'planesLavado']);
    Route::get('profesionales', [PortalController::class, 'profesionales']);
    Route::get('disponibilidad', [PortalController::class, 'disponibilidad']);
    Route::post('reservar', [PortalController::class, 'reservar']);
    Route::get('mis-citas', [PortalController::class, 'misCitas']);
    Route::post('citas/{cita}/cancelar', [PortalController::class, 'cancelar']);
});

// Compatibilidad: enlace antiguo sin slug → negocio principal.
Route::prefix('publico')->group(function () {
    Route::get('sucursales', [PortalController::class, 'sucursales']);
    Route::get('servicios', [PortalController::class, 'servicios']);
    Route::get('productos', [PortalController::class, 'productos']);
    Route::get('planes-lavado', [PortalController::class, 'planesLavado']);
    Route::get('profesionales', [PortalController::class, 'profesionales']);
    Route::get('disponibilidad', [PortalController::class, 'disponibilidad']);
    Route::post('reservar', [PortalController::class, 'reservar']);
    Route::get('mis-citas', [PortalController::class, 'misCitas']);
    Route::post('citas/{cita}/cancelar', [PortalController::class, 'cancelar']);
});

// --- Rutas protegidas (requieren token Sanctum) ---
// 'membresia' bloquea las funciones operativas si la membresía mensual venció
// (deja pasar perfil, planes, créditos y notificaciones para poder renovar).
Route::middleware(['auth:sanctum', 'membresia'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Perfil del usuario autenticado
    Route::put('/perfil', [ProfileController::class, 'update']);
    Route::post('/perfil/foto', [ProfileController::class, 'uploadFoto']);
    Route::post('/perfil/logo-empresa', [ProfileController::class, 'subirLogoEmpresa']);
    Route::put('/perfil/logo-emoji', [ProfileController::class, 'actualizarLogoEmoji']);
    Route::put('/perfil/publico', [ProfileController::class, 'actualizarPerfilPublico']);
    Route::put('/perfil/password', [ProfileController::class, 'cambiarPassword']);

    // Catálogo de planes (visible para usuarios autenticados: dashboard, "actualizar plan")
    Route::get('/planes', [PlanController::class, 'index']);
    // Pago/renovación de la membresía mensual (checkout Wompi: PSE, Nequi, tarjeta)
    Route::post('/planes/{plan}/checkout', [App\Http\Controllers\CreditController::class, 'createPlanSession']);

    // Crédito por uso (paquetes y saldo)
    Route::get('/credit-packages', [App\Http\Controllers\CreditController::class, 'indexPackages']);
    Route::get('/credits', [App\Http\Controllers\CreditController::class, 'myCredits']);
    Route::post('/credits/create-session', [App\Http\Controllers\CreditController::class, 'createSession']);

    // Funcionalidades del usuario autenticado (el frontend oculta/restringe módulos)
    Route::get('/mis-funcionalidades', [FeatureController::class, 'mias']);

    // "Mis negocios": alternar entre varias cuentas/negocios de la misma persona
    Route::get('/cuenta/mis-negocios', [CuentaController::class, 'misNegocios']);
    Route::post('/cuenta/nuevo-negocio', [CuentaController::class, 'nuevoNegocio']);
    Route::post('/cuenta/vincular-negocio', [CuentaController::class, 'vincular']);
    Route::delete('/cuenta/negocios/{negocio}', [CuentaController::class, 'desvincular']);
    Route::post('/cuenta/negocios/{negocio}/entrar', [CuentaController::class, 'entrar']);

    // ===== Panel del Super Administrador (solo super-admin) =====
    Route::middleware('superadmin')->prefix('admin')->group(function () {
        // Usuarios registrados
        Route::get('usuarios', [UsuarioAdminController::class, 'index']);
        Route::post('usuarios', [UsuarioAdminController::class, 'crearEmpleado']);
        Route::put('usuarios/{usuario}', [UsuarioAdminController::class, 'update']);
        Route::post('usuarios/{usuario}/estado', [UsuarioAdminController::class, 'cambiarEstado']);
        Route::post('usuarios/{usuario}/plan', [UsuarioAdminController::class, 'cambiarPlan']);
        Route::post('usuarios/{usuario}/limite', [UsuarioAdminController::class, 'cambiarLimite']);
        Route::post('usuarios/{usuario}/restablecer-password', [UsuarioAdminController::class, 'restablecerPassword']);
        Route::delete('usuarios/{usuario}', [UsuarioAdminController::class, 'eliminar']);
        Route::delete('usuarios/{usuario}/permanente', [UsuarioAdminController::class, 'eliminarPermanente']);

        // Control de Funcionalidades (por usuario)
        Route::get('usuarios/{usuario}/funcionalidades', [UsuarioAdminController::class, 'funcionalidades']);
        Route::put('usuarios/{usuario}/funcionalidades', [UsuarioAdminController::class, 'guardarFuncionalidades']);
        Route::post('usuarios/{usuario}/funcionalidades/aplicar-plan', [UsuarioAdminController::class, 'aplicarPlanFuncionalidades']);

        // Administración de licencias
        Route::get('licencias', [UsuarioAdminController::class, 'licencias']);

        // ===== Multiempresa: gestión de EMPRESAS (tenants) =====
        Route::get('empresas', [App\Http\Controllers\Admin\EmpresaAdminController::class, 'index']);
        Route::put('empresas/{empresa}', [App\Http\Controllers\Admin\EmpresaAdminController::class, 'update']);
        Route::post('empresas/{empresa}/estado', [App\Http\Controllers\Admin\EmpresaAdminController::class, 'cambiarEstado']);
        Route::post('empresas/{empresa}/plan', [App\Http\Controllers\Admin\EmpresaAdminController::class, 'cambiarPlan']);
        Route::post('empresas/{empresa}/limite', [App\Http\Controllers\Admin\EmpresaAdminController::class, 'cambiarLimite']);
        Route::post('empresas/{empresa}/membresia', [App\Http\Controllers\Admin\EmpresaAdminController::class, 'cambiarMembresia']);
        Route::post('empresas/{empresa}/modo-cobro', [App\Http\Controllers\Admin\EmpresaAdminController::class, 'cambiarModoCobro']);
        Route::post('empresas/{empresa}/regenerar-codigo', [App\Http\Controllers\Admin\EmpresaAdminController::class, 'regenerarCodigoActivacion']);
        Route::post('empresas/{empresa}/enviar-codigo', [App\Http\Controllers\Admin\EmpresaAdminController::class, 'enviarCodigoActivacion']);
        Route::get('empresas/{empresa}/modulos', [App\Http\Controllers\Admin\EmpresaAdminController::class, 'modulos']);
        Route::put('empresas/{empresa}/modulos', [App\Http\Controllers\Admin\EmpresaAdminController::class, 'guardarModulos']);
        Route::post('empresas/{empresa}/modulos/aplicar-plan', [App\Http\Controllers\Admin\EmpresaAdminController::class, 'aplicarPlanModulos']);

        // Catálogo de tipos de negocio (módulos por tipo)
        Route::get('tipos-negocio', [App\Http\Controllers\Admin\EmpresaAdminController::class, 'tiposNegocio']);
        Route::post('tipos-negocio', [App\Http\Controllers\Admin\EmpresaAdminController::class, 'guardarTipoNegocio']);

        // Conectar la cuenta Gmail (OAuth) para enviar correos por la API HTTP.
        // Devuelve la URL de consentimiento de Google; el super-admin la abre y autoriza.
        Route::get('gmail/conectar', function () {
            abort_unless(config('services.gmail.client_id') && config('services.gmail.client_secret'), 422,
                'Define GOOGLE_CLIENT_ID y GOOGLE_CLIENT_SECRET en el servidor primero (console.cloud.google.com).');

            // Explícita (GOOGLE_REDIRECT_URI) si está configurada; si no, se deriva de la
            // petición actual (ya con esquema HTTPS correcto gracias a trustProxies).
            $redirectUri = config('services.gmail.redirect') ?: url('/api/gmail/callback');

            $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                'client_id' => config('services.gmail.client_id'),
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => 'https://www.googleapis.com/auth/gmail.send',
                'access_type' => 'offline',
                'prompt' => 'consent',
            ]);

            return response()->json([
                'autorizar_url' => $url,
                'instrucciones' => 'Abre autorizar_url en el navegador con la cuenta Gmail remitente y acepta. El refresh token queda guardado automáticamente.',
                'redirect_uri_requerida' => $redirectUri,
            ]);
        });

        // Bitácora de auditoría
        Route::get('auditorias', [UsuarioAdminController::class, 'auditorias']);

        // Gestión de planes
        Route::post('planes', [PlanController::class, 'store']);
        Route::put('planes/{plan}', [PlanController::class, 'update']);
    });

    // Equipo del negocio: el Administrador/Usuario dueño puede crear empleados por establecimiento.
    Route::middleware('role:Administrador,Usuario')->prefix('equipo')->group(function () {
        Route::get('roles', [UsuarioAdminController::class, 'roles']);
        Route::get('usuarios', [UsuarioAdminController::class, 'miEquipo']);
        Route::post('usuarios', [UsuarioAdminController::class, 'crearEmpleado']);
        Route::post('usuarios/quick', [UsuarioAdminController::class, 'crearEmpleadoRapido']);
        Route::put('usuarios/{usuario}', [UsuarioAdminController::class, 'actualizarEquipo']);
        Route::post('usuarios/{usuario}/estado', [UsuarioAdminController::class, 'estadoEquipo']);
        Route::get('auditorias', [UsuarioAdminController::class, 'auditorias']);
    });

    // Ejemplo de ruta restringida por rol (RBAC) — solo Administrador
    Route::get('/admin/ping', function () {
        return response()->json(['message' => 'Hola, Administrador.']);
    })->middleware('role:Administrador');

    // ===== FASE 3: Inventario y Proveedores =====

    // --- Lecturas: cualquier usuario autenticado ---
    Route::get('categorias', [CategoriaController::class, 'index']);
    Route::get('bodegas', [BodegaController::class, 'index'])->middleware('feature:inventario');
    Route::get('bodegas/{bodega}/servicios', [BodegaController::class, 'servicios'])->middleware('feature:inventario');
    Route::get('productos', [ProductoController::class, 'index'])->middleware('feature:productos');
    Route::get('productos/{producto}', [ProductoController::class, 'show'])->middleware('feature:productos');
    Route::middleware('feature:inventario')->group(function () {
        Route::get('inventario/stock', [InventarioController::class, 'stock']);
        Route::get('inventario/movimientos', [InventarioController::class, 'movimientos']);
        Route::get('inventario/alertas', [InventarioController::class, 'alertas']);
    });

    // --- Proveedores: Administrador y Ventas/Compras ---
    Route::middleware('role:Administrador,Ventas/Compras')->group(function () {
        // Lectura inteligente de documentos (autocompletar con la API de Claude) — solo planes con OCR
        Route::post('proveedores/extraer', [ExtraccionController::class, 'proveedor'])->middleware('feature:ocr');
        Route::apiResource('proveedores', ProveedorController::class)->parameters(['proveedores' => 'proveedor'])->middleware('feature:proveedores');
    });

    // --- Escritura de inventario: Administrador y Almacenista ---
    Route::middleware('role:Administrador,Almacenista')->group(function () {
        Route::apiResource('categorias', CategoriaController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('bodegas', BodegaController::class)->only(['store', 'update', 'destroy'])->middleware('feature:inventario');
        Route::post('bodegas/{bodega}/principal', [BodegaController::class, 'definirPrincipal'])->middleware('feature:inventario');
        Route::put('bodegas/{bodega}/servicios', [BodegaController::class, 'sincronizarServicios']);
        Route::post('productos/{producto}/update', [ProductoController::class, 'update'])->middleware('feature:productos'); // multipart (imagen)
        Route::apiResource('productos', ProductoController::class)->only(['store', 'update', 'destroy'])->middleware('feature:productos');
        Route::post('productos/{producto}/galeria', [GaleriaController::class, 'subirProducto'])->middleware('feature:productos');
        Route::delete('productos/{producto}/galeria/{imagen}', [GaleriaController::class, 'eliminarProducto'])->middleware('feature:productos');

        Route::post('inventario/movimientos', [InventarioController::class, 'registrarMovimiento'])->middleware('feature:inventario');
        Route::delete('inventario/movimientos/{movimiento}', [InventarioController::class, 'eliminarMovimiento'])->middleware('feature:inventario');
        Route::delete('inventario/stock/{stock}', [InventarioController::class, 'eliminarStock'])->middleware('feature:inventario');
        Route::put('inventario/stock/{stock}', [InventarioController::class, 'editarStock'])->middleware('feature:inventario');
        Route::post('inventario/minimo', [InventarioController::class, 'definirMinimo'])->middleware('feature:inventario');
    });

    // ===== BLOQUE A: Clientes (CRM) =====
    Route::middleware(['role:Administrador,Ventas/Compras,Empleado', 'feature:clientes'])->group(function () {
        Route::apiResource('clientes', ClienteController::class);
    });

    // ===== BLOQUE B: Agenda, Citas y Servicios =====
    Route::get('servicios', [ServicioController::class, 'index']);
    Route::get('planes-lavado', [PlanLavadoController::class, 'index']);
    Route::get('citas', [CitaController::class, 'index']);
    Route::get('citas/disponibilidad', [CitaController::class, 'disponibilidad']);
    Route::get('citas/{cita}', [CitaController::class, 'show']);
    Route::get('personal', [CitaController::class, 'personal']);
    Route::get('agenda/configuracion', [ConfiguracionAgendaController::class, 'index']);

    Route::middleware(['role:Administrador,Empleado', 'feature:agenda'])->group(function () {
        Route::post('citas', [CitaController::class, 'store']);
        Route::put('citas/{cita}', [CitaController::class, 'update']);
        Route::post('citas/{cita}/reprogramar', [CitaController::class, 'reprogramar']);
        Route::post('citas/{cita}/confirmar', [CitaController::class, 'confirmar']);
        Route::post('citas/{cita}/cancelar', [CitaController::class, 'cancelar']);
    });

    // Configuración de servicios y calendario (solo Administrador)
    Route::middleware('role:Administrador')->group(function () {
        Route::post('servicios', [ServicioController::class, 'store']);
        Route::post('servicios/{servicio}/update', [ServicioController::class, 'update']); // multipart (imagen)
        Route::put('servicios/{servicio}', [ServicioController::class, 'update']);
        Route::delete('servicios/{servicio}', [ServicioController::class, 'destroy']);
        Route::post('servicios/{servicio}/galeria', [GaleriaController::class, 'subirServicio']);
        Route::delete('servicios/{servicio}/galeria/{imagen}', [GaleriaController::class, 'eliminarServicio']);
        Route::post('planes-lavado', [PlanLavadoController::class, 'store']);
        Route::put('planes-lavado/{planLavado}', [PlanLavadoController::class, 'update']);
        Route::delete('planes-lavado/{planLavado}', [PlanLavadoController::class, 'destroy']);
        Route::put('agenda/ajustes', [ConfiguracionAgendaController::class, 'guardarAjustes']);
        Route::put('agenda/horarios', [ConfiguracionAgendaController::class, 'guardarHorarios']);
        Route::post('agenda/bloqueos', [ConfiguracionAgendaController::class, 'crearBloqueo']);
        Route::delete('agenda/bloqueos/{bloqueo}', [ConfiguracionAgendaController::class, 'eliminarBloqueo']);
    });

    // ===== FASE 4: Documentación y Firma electrónica =====

    // Órdenes de compra (Administrador y Ventas/Compras)
    Route::middleware('role:Administrador,Ventas/Compras')->group(function () {
        Route::get('ordenes-compra', [OrdenCompraController::class, 'index']);
        Route::post('ordenes-compra', [OrdenCompraController::class, 'store']);
        Route::get('ordenes-compra/{orden}', [OrdenCompraController::class, 'show']);
        Route::post('ordenes-compra/{orden}/recibir', [OrdenCompraController::class, 'recibir']);
        Route::post('ordenes-compra/{orden}/pdf', [DocumentoController::class, 'generarOrdenCompra']);
    });

    // Gestión documental (adjuntos) de proveedores y clientes — solo planes con gestión documental
    Route::middleware('feature:documental')->group(function () {
        Route::get('adjuntos', [AdjuntoController::class, 'index']);
        Route::post('adjuntos', [AdjuntoController::class, 'store']);
        Route::post('adjuntos/{adjunto}/reemplazar', [AdjuntoController::class, 'reemplazar']);
        Route::delete('adjuntos/{adjunto}', [AdjuntoController::class, 'destroy']);
    });

    // Documentos y firma (lectura para todos; firma para Administrador)
    Route::get('documentos', [DocumentoController::class, 'index']);
    Route::middleware('role:Administrador')->group(function () {
        Route::post('documentos/{documento}/firma', [FirmaController::class, 'iniciar']);
        Route::patch('firmas/{firma}', [FirmaController::class, 'actualizarEstado']);
    });

    // ===== FASE 5: Reportes, Dashboard y Exportación =====
    Route::get('reportes/dashboard', [ReporteController::class, 'dashboard']);
    Route::get('reportes/inventario/excel', [ReporteController::class, 'exportarInventarioExcel'])->middleware('feature:exportacion');

    // ===== BLOQUE D: Facturación a clientes + Notificaciones =====
    Route::middleware('feature:facturacion')->group(function () {
        Route::get('facturas', [FacturaController::class, 'index']);
        Route::get('facturas/{factura}/pdf', [FacturaController::class, 'verPdf'])->middleware('feature:pdf');
        Route::get('facturas/{factura}', [FacturaController::class, 'show']);
        Route::get('facturas/{factura}/pagos', [FacturaController::class, 'pagos']);
        Route::middleware('role:Administrador,Ventas/Compras')->group(function () {
            Route::post('facturas', [FacturaController::class, 'store']);
            Route::put('facturas/{factura}', [FacturaController::class, 'update']);
            Route::delete('facturas/{factura}', [FacturaController::class, 'destroy']);
            Route::post('facturas/{factura}/pdf', [FacturaController::class, 'generarPdf'])->middleware('feature:pdf');
            Route::post('facturas/{factura}/enviar', [FacturaController::class, 'enviar'])->middleware('feature:correos');
            Route::post('facturas/{factura}/whatsapp', [FacturaController::class, 'whatsapp'])->middleware('feature:pdf');
            Route::post('facturas/{factura}/pagos', [FacturaController::class, 'registrarPago']);
        });

        // Métodos de pago manuales del negocio (Nequi, Daviplata, Bancolombia,
        // link de pago...): se muestran en el modal de cobro para que el
        // cliente escanee el QR o vea los datos de transferencia.
        Route::get('metodos-pago', [MetodoPagoCobroController::class, 'index']);
        Route::middleware('role:Administrador')->group(function () {
            Route::post('metodos-pago', [MetodoPagoCobroController::class, 'store']);
            Route::post('metodos-pago/{metodoPago}/update', [MetodoPagoCobroController::class, 'update']); // multipart (QR)
            Route::put('metodos-pago/{metodoPago}', [MetodoPagoCobroController::class, 'update']);
            Route::delete('metodos-pago/{metodoPago}', [MetodoPagoCobroController::class, 'destroy']);
        });
    });

    // Notificaciones (del usuario actual)
    Route::get('notificaciones', [NotificacionController::class, 'index']);
    Route::get('notificaciones/no-leidas', [NotificacionController::class, 'noLeidas']);
    Route::post('notificaciones/marcar-leidas', [NotificacionController::class, 'marcarLeidas']);

    // ===== BLOQUE NUEVO: Sistema POS Híbrido (Servicios + Productos) =====
    Route::middleware('feature:servicios')->group(function () {
        // Empleados operables (mecánicos, estilistas, técnicos, etc)
        Route::get('empleados/tipos', [OperablesEmployeeController::class, 'tipos']);
        Route::apiResource('empleados', OperablesEmployeeController::class, ['parameters' => ['empleados' => 'operablesEmployee']]);
        Route::post('empleados/{operablesEmployee}/galeria', [GaleriaController::class, 'subirArtista']);
        Route::delete('empleados/{operablesEmployee}/galeria/{imagen}', [GaleriaController::class, 'eliminarArtista']);

        // Activos/Vehículos (motos, autos, celulares, etc)
        Route::get('activos/tipos', [AssetVehicleController::class, 'tipos']);
        Route::get('activos/buscar-placa', [AssetVehicleController::class, 'buscarPorPlaca']);
        Route::get('activos/{assetVehicle}/hoja-vida', [AssetVehicleController::class, 'hojaDeVida']);
        Route::apiResource('activos', AssetVehicleController::class, ['parameters' => ['activos' => 'assetVehicle']]);

        // Órdenes de servicio (el nuevo motor de facturación)
        Route::get('ordenes-servicio/estados', [ServiceOrderController::class, 'estados']);
        Route::get('ordenes-servicio/buscar', [ServiceOrderController::class, 'buscarPorPlacaOOrden']);
        Route::post('ordenes-servicio/{serviceOrder}/detalles', [ServiceOrderController::class, 'agregarDetalle']);
        Route::put('ordenes-servicio/{serviceOrder}/detalles/{detail}', [ServiceOrderController::class, 'actualizarDetalle']);
        Route::delete('ordenes-servicio/{serviceOrder}/detalles/{detail}', [ServiceOrderController::class, 'eliminarDetalle']);
        Route::post('ordenes-servicio/{serviceOrder}/preparar-facturacion', [ServiceOrderController::class, 'prepararFacturacion']);
        // Cobro desde la caja: genera la factura de la orden con medio de pago y envía el recibo.
        Route::post('ordenes-servicio/{serviceOrder}/facturar', [FacturaController::class, 'facturarOrden']);
        Route::post('ordenes-servicio/{serviceOrder}/completar', [ServiceOrderController::class, 'completar']);
        Route::apiResource('ordenes-servicio', ServiceOrderController::class, ['parameters' => ['ordenes-servicio' => 'serviceOrder']]);

        // Reportes: Comisiones y Hoja de vida
        Route::get('reportes/liquidaciones-comisiones', [ReportController::class, 'liquidacionesComisiones']);
        Route::post('reportes/liquidaciones-comisiones/{liquidacion}/marcar-pagada', [ReportController::class, 'marcarPagada']);
        Route::post('reportes/generar-liquidacion', [ReportController::class, 'generarLiquidacion']);
        Route::get('reportes/hoja-vida-activo', [ReportController::class, 'hojaDeVidaActivo']);
        Route::get('reportes/comisiones-por-empleado', [ReportController::class, 'comisionesPorEmpleado']);
    });

    // ===== BLOQUE RESTAURANTE: Plano de mesas, comandas y cocina (KDS) =====
    Route::middleware('feature:mesas')->group(function () {
        Route::apiResource('mesas', \App\Http\Controllers\MesaController::class)->except('show');
        Route::post('mesas/{mesa}/comanda', [\App\Http\Controllers\ComandaController::class, 'abrir']);
        Route::get('comandas/{comanda}', [\App\Http\Controllers\ComandaController::class, 'show']);
        Route::post('comandas/{comanda}/items', [\App\Http\Controllers\ComandaController::class, 'agregarItem']);
        Route::delete('comandas/{comanda}/items/{item}', [\App\Http\Controllers\ComandaController::class, 'quitarItem']);
        Route::post('comandas/{comanda}/cobrar', [\App\Http\Controllers\ComandaController::class, 'cobrar']);
        Route::post('comandas/{comanda}/cancelar', [\App\Http\Controllers\ComandaController::class, 'cancelar']);
    });
    // Cocina (KDS): pantalla dedicada, feature separada (puede asignarse solo al personal de cocina).
    Route::middleware('feature:cocina')->group(function () {
        Route::get('cocina/comandas', [\App\Http\Controllers\ComandaController::class, 'cocina']);
        Route::put('cocina/items/{item}/estado', [\App\Http\Controllers\ComandaController::class, 'estadoItem']);
    });

    // ===== BLOQUE E: Bloc de notas =====
    Route::apiResource('notas', NotaController::class)->except('show')->middleware('feature:notas');

    // ===== POS: Caja (apertura/cierre con arqueo) y Gastos diarios =====
    // El rol Mecanico no maneja dinero: queda excluido por rol.
    Route::middleware('role:Administrador,Usuario,Ventas/Compras,Empleado,Almacenista')->group(function () {
        Route::get('caja/sesiones', [App\Http\Controllers\CajaController::class, 'index']);
        Route::get('caja/actual', [App\Http\Controllers\CajaController::class, 'actual']);
        Route::post('caja/abrir', [App\Http\Controllers\CajaController::class, 'abrir']);
        Route::post('caja/ingresos', [App\Http\Controllers\CajaController::class, 'storeIngreso']);
        Route::post('caja/{sesion}/cerrar', [App\Http\Controllers\CajaController::class, 'cerrar']);

        Route::get('gastos', [App\Http\Controllers\GastoController::class, 'index']);
        Route::post('gastos', [App\Http\Controllers\GastoController::class, 'store']);
        Route::delete('gastos/{gasto}', [App\Http\Controllers\GastoController::class, 'destroy']);
        Route::get('reportes/utilidad-dia', [App\Http\Controllers\GastoController::class, 'utilidadDia']);

        // Fidelización: historial del cliente por placa o cédula en el POS.
        Route::get('pos/historial-cliente', [ClienteController::class, 'historialPos']);
    });
});

// PDF público de factura con firma HMAC (enlace de WhatsApp/correo; regenera si el disco efímero lo borró).
Route::get('publico/facturas/{factura}/pdf', [FacturaController::class, 'pdfPublico']);

// Callback de OAuth de Google (autorización de la API de Gmail para enviar correos).
// Google redirige aquí tras el consentimiento; guardamos el refresh token en la BD.
Route::get('gmail/callback', function (Illuminate\Http\Request $request) {
    abort_unless($request->query('code'), 400, 'Falta el código de autorización de Google.');

    // Debe ser IDÉNTICA a la enviada en /gmail/conectar (Google la valida por igualdad exacta).
    $redirectUri = config('services.gmail.redirect') ?: url('/api/gmail/callback');

    $res = Illuminate\Support\Facades\Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
        'client_id' => config('services.gmail.client_id'),
        'client_secret' => config('services.gmail.client_secret'),
        'code' => $request->query('code'),
        'grant_type' => 'authorization_code',
        'redirect_uri' => $redirectUri,
    ]);

    if ($res->failed() || ! $res->json('refresh_token')) {
        return response(
            '<h2>❌ No se pudo conectar Gmail</h2><p>' . e($res->json('error_description') ?? $res->body()) . '</p>'
            . '<p>Vuelve a intentarlo desde /api/admin/gmail/conectar (el consentimiento debe otorgarse de nuevo).</p>',
            422
        )->header('Content-Type', 'text/html; charset=utf-8');
    }

    Illuminate\Support\Facades\Cache::forever(\App\Mail\Transport\GmailApiTransport::CACHE_REFRESH_TOKEN, $res->json('refresh_token'));
    Illuminate\Support\Facades\Cache::forget('gmail_access_token');

    return response(
        '<h2>✅ Gmail conectado</h2><p>Logix ya puede enviar correos por la API de Gmail (HTTPS). '
        . 'Puedes cerrar esta pestaña. Los correos pendientes saldrán en el próximo arranque o reintento.</p>'
    )->header('Content-Type', 'text/html; charset=utf-8');
});

// Public webhooks for payment providers (no auth). Protect with provider signature in production.
Route::post('webhooks/payments/{provider}', [\App\Http\Controllers\PaymentWebhookController::class, 'handle']);
