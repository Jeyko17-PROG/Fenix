<?php

namespace App\Http\Controllers;

use App\Models\Archivo;
use App\Models\Cita;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Servicio;
use App\Models\User;
use App\Services\AgendaService;
use App\Services\CloudinaryUploader;
use App\Services\Notificador;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Endpoints PÚBLICOS del portal de clientes (sin autenticación).
 * Es el destino del QR y del enlace público de reservas.
 *
 * El portal es POR USUARIO: la URL incluye el slug del negocio
 * (ej. /publico/{slug}/...), de modo que cada QR lleva al calendario correcto
 * y las reservas quedan en la cuenta de su propietario. Si no se indica slug,
 * se usa el negocio principal (compatibilidad con el enlace antiguo).
 */
class PortalController extends Controller
{
    public function __construct(
        private AgendaService $agenda,
        private Notificador $notificador,
        private CloudinaryUploader $cloudinary,
    ) {}

    /**
     * Sube la foto de referencia que el cliente adjuntó al reservar (ej. la
     * idea de tatuaje que quiere) a Cloudinary. Si falla, no bloquea la
     * reserva — solo se queda sin la foto (igual criterio que las imágenes
     * de productos/servicios).
     */
    private function subirReferenciaCliente(Request $request, int $negocio): ?string
    {
        $file = $request->file('imagen_referencia');
        $publicId = "logix/citas/referencias_cliente/{$negocio}/" . Str::random(12);

        try {
            $resultado = $this->cloudinary->subir($file->getRealPath(), $publicId);
        } catch (\Throwable $e) {
            Log::error('Cloudinary: fallo al subir referencia del cliente', ['negocio' => $negocio, 'error' => $e->getMessage()]);
            return null;
        }

        Archivo::create([
            'nombre_original' => $file->getClientOriginalName(),
            'ruta' => $resultado['public_id'],
            'url' => $resultado['secure_url'],
            'tipo_mime' => $file->getClientMimeType(),
            'tamano_bytes' => $file->getSize(),
        ]);

        return $resultado['secure_url'];
    }

    /**
     * Resuelve el id del usuario dueño del portal a partir del slug público.
     * Multiempresa: primero busca el slug en empresas (canónico) y cae al
     * slug antiguo de users para no romper QRs/enlaces existentes.
     */
    private function negocioId(?string $slug = null): int
    {
        if ($slug) {
            $id = \App\Models\Empresa::where('reservas_slug', $slug)->value('owner_user_id')
                ?? User::where('reservas_slug', $slug)->value('id');
            abort_if(! $id, 404, 'Portal de reservas no encontrado.');
            return (int) $id;
        }
        return User::negocioPrincipalId() ?? abort(404, 'Portal de reservas no disponible.');
    }

    /** Datos básicos del negocio (para el encabezado del portal). */
    public function negocio(?string $slug = null)
    {
        $id = $this->negocioId($slug);
        $u = User::find($id);
        $empresa = \App\Models\Empresa::with('tipoNegocio:id,clave,nombre')->where('owner_user_id', $id)->first();
        return response()->json([
            'nombre' => $empresa?->nombre ?? $u?->name,
            'slug' => $empresa?->reservas_slug ?? $u?->reservas_slug,
            'logo_url' => $empresa?->logo_url,
            'logo_emoji' => $empresa?->logo_emoji,
            'tipo_negocio' => $empresa?->tipoNegocio?->clave,
            // Para el botón "Escribir al local" en la pantalla de confirmación.
            'telefono' => $empresa?->telefono,
            'direccion' => $empresa?->direccion,
            'politicas' => $empresa?->politicas,
            'redes' => [
                'instagram' => $empresa?->instagram_url,
                'tiktok' => $empresa?->tiktok_url,
                'facebook' => $empresa?->facebook_url,
                'whatsapp' => $empresa?->whatsapp_url,
            ],
        ]);
    }

    /**
     * Especialistas disponibles para elegir en el portal (barbería/spa):
     * roster operativo del negocio (barbero/estilista/esteticien), sin
     * necesidad de que tengan cuenta de acceso al sistema.
     */
    public function profesionales(?string $slug = null)
    {
        $negocio = $this->negocioId($slug);
        $tipoNegocio = $this->tipoNegocioDe($negocio);

        $tiposRelevantes = match ($tipoNegocio) {
            'barberia' => ['barbero'],
            'spa' => ['esteticien', 'barbero'],
            'tatuajes' => ['tatuador'],
            'accesorios_motos' => ['instalador'],
            default => ['barbero', 'esteticien', 'tatuador'],
        };

        return \App\Models\OperablesEmployee::where('owner_id', $negocio)
            ->where('activo', true)
            ->whereIn('tipo_operario', $tiposRelevantes)
            ->with('galeria:id,imageable_type,imageable_id,url,orden')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'apellido', 'tipo_operario', 'especialidad']);
    }

    /** Sucursales activas del negocio (multisucursal); el portal las ofrece como primer paso. */
    public function sucursales(?string $slug = null)
    {
        return \App\Models\Bodega::where('owner_id', $this->negocioId($slug))
            ->where('activo', true)
            ->orderByDesc('es_principal')->orderBy('nombre')
            ->get(['id', 'nombre', 'direccion', 'telefono', 'ciudad']);
    }

    /**
     * Servicios activos que el cliente puede reservar, agrupados por categoría.
     * Si se indica bodega_id, solo trae los servicios de esa sucursal (un servicio
     * sin sucursales asignadas se considera disponible en todas).
     */
    public function servicios(Request $request, ?string $slug = null)
    {
        $bodegaId = $request->query('bodega_id');

        return Servicio::where('owner_id', $this->negocioId($slug))
            ->where('activo', true)
            ->when($bodegaId, fn ($q) => $q->where(
                fn ($w) => $w->whereDoesntHave('bodegas')->orWhereHas('bodegas', fn ($b) => $b->where('bodegas.id', $bodegaId))
            ))
            ->with(['categoria:id,nombre', 'galeria:id,imageable_type,imageable_id,url,orden'])
            ->orderBy('nombre')
            ->get(['id', 'categoria_id', 'nombre', 'descripcion', 'imagen', 'icono', 'duracion_min', 'precio']);
    }

    /**
     * Catálogo público de productos (fotos, precio y disponibilidad), agrupado
     * por categoría. Aplica a cualquier tipo de negocio: es una vitrina, no
     * requiere agendar cita. "disponible" es el interruptor manual del dueño
     * (independiente del stock), pensado para negocios que no llevan
     * inventario exacto por bodega en todo lo que exhiben.
     */
    public function productos(?string $slug = null)
    {
        return Producto::where('owner_id', $this->negocioId($slug))
            ->where('activo', true)
            ->where('is_service', false)
            ->with('categoria:id,nombre')
            ->orderBy('nombre')
            ->get(['id', 'categoria_id', 'nombre', 'descripcion', 'imagen_url', 'precio_venta', 'disponible']);
    }

    /** Planes de lavado activos que el cliente puede reservar (Lavadero). */
    public function planesLavado(?string $slug = null)
    {
        return \App\Models\PlanLavado::where('owner_id', $this->negocioId($slug))
            ->where('activo', true)->orderBy('orden')->orderBy('nombre')
            ->get(['id', 'nombre', 'descripcion', 'duracion_min', 'precio', 'aplica_moto', 'aplica_carro', 'icono']);
    }

    /** Horarios disponibles en tiempo real para una fecha. */
    public function disponibilidad(Request $request, ?string $slug = null)
    {
        $negocio = $this->negocioId($slug);

        $data = $request->validate([
            'fecha' => ['required', 'date'],
            'servicio_id' => ['nullable', 'exists:servicios,id'],
            'plan_lavado_id' => ['nullable', 'exists:planes_lavado,id'],
            'bodega_id' => ['nullable', \Illuminate\Validation\Rule::exists('bodegas', 'id')->where('owner_id', $negocio)],
            // Especialista elegido (barbería/spa): la disponibilidad pasa a ser la suya.
            'operables_employee_id' => ['nullable', \Illuminate\Validation\Rule::exists('operables_employees', 'id')->where('owner_id', $negocio)],
            // Spa: varios servicios en una misma reserva; el frontend suma sus
            // duraciones y la manda aquí directo en vez de un servicio_id único.
            'duracion_min' => ['nullable', 'integer', 'min:1'],
        ]);

        $duracion = $data['duracion_min'] ?? 30;
        if (empty($data['duracion_min'])) {
            if (! empty($data['plan_lavado_id']) && $p = \App\Models\PlanLavado::find($data['plan_lavado_id'])) {
                $duracion = $p->duracion_min;
            } elseif (! empty($data['servicio_id']) && $s = Servicio::find($data['servicio_id'])) {
                $duracion = $s->duracion_min;
            }
        }

        $slots = $this->agenda->slotsDisponibles(
            Carbon::parse($data['fecha']), $duracion, $negocio,
            $data['bodega_id'] ?? null, $data['operables_employee_id'] ?? null,
        );
        return response()->json(['slots' => $slots]);
    }

    /** El cliente reserva una cita desde el portal/QR. */
    public function reservar(Request $request, ?string $slug = null)
    {
        $negocio = $this->negocioId($slug);

        // Restricción por plan: bloquea nuevas reservas si el negocio ya alcanzó su límite de citas.
        $duenoNegocio = User::find($negocio);
        if ($duenoNegocio && ! $duenoNegocio->esSuperAdmin()) {
            $limite = $duenoNegocio->limiteCitasEfectivo();
            $usadas = $duenoNegocio->citasUsadas();
            if ($usadas >= $limite) {
                return response()->json([
                    'message' => 'Este negocio alcanzó el límite de citas disponibles por ahora. Intenta más tarde o contacta directamente al negocio.',
                    'limite_alcanzado' => true,
                ], 403);
            }
        }

        $tipoNegocio = $this->tipoNegocioDe($negocio);
        $conVehiculo = $tipoNegocio === 'lavadero';
        // Spa, barbería y tatuajes permiten elegir varios servicios en una
        // misma reserva (ej. corte + barba, o varios estilos de tatuaje).
        $conVariosServicios = in_array($tipoNegocio, ['spa', 'barberia', 'tatuajes'], true);
        $conTatuaje = $tipoNegocio === 'tatuajes';

        $data = $request->validate([
            'nombre_completo' => ['required', 'string', 'max:255'],
            // El correo es opcional (flujo ultra-simple de barbería/spa): se pide
            // solo nombre y teléfono. Si el cliente lo da, recibe confirmación
            // por correo y puede consultar sus citas después con ese correo.
            'email' => ['nullable', 'email'],
            'telefono' => ['required', 'string', 'max:50'],
            'nota' => ['nullable', 'string', 'max:255'],
            'servicio_id' => ['nullable', 'exists:servicios,id'],
            // Varios servicios en una misma reserva (solo Spa: ej. Uñas + Pestañas).
            'servicios' => ['nullable', 'array', 'min:1'],
            'servicios.*.servicio_id' => ['nullable', 'exists:servicios,id'],
            'servicios.*.precio_unitario' => ['nullable', 'numeric', 'min:0'],
            'servicios.*.duracion_min' => ['nullable', 'integer', 'min:1'],
            'plan_lavado_id' => ['nullable', 'exists:planes_lavado,id'],
            'bodega_id' => ['nullable', \Illuminate\Validation\Rule::exists('bodegas', 'id')->where('owner_id', $negocio)],
            // Especialista elegido por el cliente (barbería/spa/tatuajes); null = cualquiera disponible.
            'operables_employee_id' => ['nullable', \Illuminate\Validation\Rule::exists('operables_employees', 'id')->where('owner_id', $negocio)],
            'tipo_vehiculo' => [$conVehiculo ? 'required' : 'nullable', 'in:moto,carro'],
            'placa' => [$conVehiculo ? 'required' : 'nullable', 'string', 'max:20'],
            // Estudio de tatuajes: zona del cuerpo y tamaño aproximado del tatuaje.
            'zona_cuerpo' => [$conTatuaje ? 'required' : 'nullable', 'string', 'max:100'],
            'tamano_tatuaje' => ['nullable', 'string', 'max:50'],
            'inicio' => ['required', 'date'],
            // Foto de referencia elegida de la galería del servicio (ej. el corte
            // de cabello que le gustó); debe ser una imagen real de ESE servicio,
            // no cualquier URL, para evitar que se cuele contenido ajeno. Si el
            // cliente en cambio SUBE su propia foto (ej. la idea de tatuaje que
            // quiere), llega como archivo en 'imagen_referencia' y no pasa por
            // esta validación — se sube aparte más abajo.
            'imagen_referencia_url' => [
                'nullable', 'string', 'max:2048',
                function ($attribute, $value, $fail) use ($negocio) {
                    $servicioId = request('servicio_id');
                    $existe = \App\Models\GaleriaImagen::where('owner_id', $negocio)
                        ->where('imageable_type', Servicio::class)
                        ->when($servicioId, fn ($q) => $q->where('imageable_id', $servicioId))
                        ->where('url', $value)
                        ->exists();
                    if (! $existe) {
                        $fail('La imagen de referencia no es válida para este servicio.');
                    }
                },
            ],
            // Foto propia del cliente (ej. la idea del tatuaje que quiere) — alternativa a elegir de la galería.
            'imagen_referencia' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:8192'],
        ]);

        // Si el cliente subió su propia foto, se sube a Cloudinary y reemplaza
        // cualquier selección de la galería (una reserva no necesita ambas).
        if ($request->hasFile('imagen_referencia')) {
            $data['imagen_referencia_url'] = $this->subirReferenciaCliente($request, $negocio);
        }

        // El campo "servicios" (varios por reserva) solo aplica a negocios Spa;
        // en cualquier otro tipo se ignora, igual que tipo_vehiculo/placa fuera de Lavadero.
        $servicios = $conVariosServicios ? ($data['servicios'] ?? null) : null;
        unset($data['servicios']);
        foreach ($servicios ?? [] as $i => $item) {
            if (empty($item['servicio_id'])) {
                throw ValidationException::withMessages(["servicios.$i" => 'Selecciona un servicio del catálogo.']);
            }
        }

        $duracion = 30;
        if ($servicios) {
            $duracion = 0;
            foreach ($servicios as $item) {
                $min = $item['duracion_min'] ?? Servicio::find($item['servicio_id'])?->duracion_min;
                $duracion += (int) ($min ?? 0);
            }
            $duracion = $duracion ?: 30;
        } elseif (! empty($data['plan_lavado_id']) && $p = \App\Models\PlanLavado::find($data['plan_lavado_id'])) {
            $duracion = $p->duracion_min;
        } elseif (! empty($data['servicio_id']) && $s = Servicio::find($data['servicio_id'])) {
            $duracion = $s->duracion_min;
        }

        $inicio = Carbon::parse($data['inicio']);
        $fin = $inicio->copy()->addMinutes($duracion);

        // Garantía anti doble-reserva (misma lógica que el panel admin; scoped
        // por sucursal y, si se eligió, por el especialista).
        $this->agenda->asegurarDisponible($inicio, $fin, null, $negocio, $data['bodega_id'] ?? null, $data['operables_employee_id'] ?? null);

        // El portal es público (sin sesión), así que hay que fijar empresa_id a
        // mano: el dueño autenticado filtra su Agenda/Clientes por empresa_id,
        // y sin esto las reservas del QR quedarían invisibles para él.
        $empresaId = $this->empresaIdDe($negocio);

        if ($servicios && empty($data['servicio_id'])) {
            $data['servicio_id'] = $servicios[0]['servicio_id'] ?? null;
        }

        return DB::transaction(function () use ($data, $servicios, $inicio, $fin, $negocio, $empresaId) {
            // Reutiliza el cliente del negocio por email; si no dio correo
            // (flujo simplificado), lo identifica por teléfono en su lugar.
            $criterioBusqueda = ! empty($data['email'])
                ? ['email' => $data['email'], 'owner_id' => $negocio]
                : ['telefono' => $data['telefono'], 'owner_id' => $negocio];

            $cliente = Cliente::firstOrCreate($criterioBusqueda, [
                'nombre_completo' => $data['nombre_completo'],
                'email' => $data['email'] ?? null,
                'telefono' => $data['telefono'],
                'estado' => 'POTENCIAL',
                'empresa_id' => $empresaId,
            ]);
            if ($empresaId && ! $cliente->empresa_id) {
                $cliente->update(['empresa_id' => $empresaId]);
            }

            $cita = Cita::create([
                'owner_id' => $negocio,
                'empresa_id' => $empresaId,
                'cliente_id' => $cliente->id,
                'servicio_id' => $data['servicio_id'] ?? null,
                'plan_lavado_id' => $data['plan_lavado_id'] ?? null,
                'bodega_id' => $data['bodega_id'] ?? null,
                'operables_employee_id' => $data['operables_employee_id'] ?? null,
                'tipo_vehiculo' => $data['tipo_vehiculo'] ?? null,
                'placa' => $data['placa'] ?? null,
                'zona_cuerpo' => $data['zona_cuerpo'] ?? null,
                'tamano_tatuaje' => $data['tamano_tatuaje'] ?? null,
                'observaciones' => $data['nota'] ?? null,
                'imagen_referencia_url' => $data['imagen_referencia_url'] ?? null,
                'inicio' => $inicio,
                'fin' => $fin,
                'estado' => 'PENDIENTE',
                'origen' => 'PORTAL',
            ]);

            if ($servicios) {
                foreach ($servicios as $item) {
                    $catalogo = Servicio::find($item['servicio_id']);
                    $cita->detalleServicios()->create([
                        'servicio_id' => $item['servicio_id'],
                        'precio_unitario' => $item['precio_unitario'] ?? $catalogo?->precio ?? 0,
                        'duracion_min' => $item['duracion_min'] ?? $catalogo?->duracion_min ?? 0,
                    ]);
                }
            }

            // Notificación interna SOLO para el dueño del negocio + correo al
            // cliente (solo si dejó su correo: el flujo simplificado no lo exige).
            $this->notificador->aUsuario($negocio, 'RESERVA',
                'Nueva reserva desde el portal', "{$cliente->nombre_completo} · {$inicio->format('d/m/Y H:i')}");
            if ($cliente->email) {
                $this->notificador->correo($cliente->email, 'Confirmación de tu reserva - Fénix',
                    '¡Reserva confirmada!', [
                        "Hola {$cliente->nombre_completo},",
                        "Tu cita quedó agendada para el {$inicio->format('d/m/Y')} a las {$inicio->format('H:i')}.",
                        'Si necesitas cancelar, ingresa al portal con tu correo.',
                    ], null, 'RESERVA');
            }

            return response()->json([
                'mensaje' => 'Reserva confirmada.',
                'cita' => $cita->load(
                    'servicio:id,nombre,icono', 'planLavado:id,nombre,icono', 'bodega:id,nombre',
                    'detalleServicios.servicio:id,nombre,icono', 'operablesEmployee:id,nombre,apellido',
                ),
            ], 201);
        });
    }

    /** Clave del tipo de negocio del dueño resuelto por negocioId(). */
    private function tipoNegocioDe(int $negocioId): ?string
    {
        return \App\Models\Empresa::with('tipoNegocio:id,clave')
            ->where('owner_user_id', $negocioId)->first()?->tipoNegocio?->clave;
    }

    /**
     * Id de la empresa (tenant) del dueño resuelto por negocioId(). El portal
     * es público (sin usuario autenticado), así que PerteneceAUsuario NO
     * asigna empresa_id automáticamente al crear; hay que fijarlo a mano o el
     * dueño (que sí filtra por empresa_id) nunca vería estas citas/clientes.
     */
    private function empresaIdDe(int $negocioId): ?int
    {
        return \App\Models\Empresa::where('owner_user_id', $negocioId)->value('id');
    }

    /** Consulta de citas del cliente por su correo (sin login, apto móvil). */
    public function misCitas(Request $request, ?string $slug = null)
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $cliente = Cliente::where('owner_id', $this->negocioId($slug))
            ->where('email', $data['email'])->first();
        if (! $cliente) {
            return response()->json(['citas' => []]);
        }

        $citas = $cliente->citas()
            ->with('servicio:id,nombre,icono', 'planLavado:id,nombre,icono', 'bodega:id,nombre', 'detalleServicios.servicio:id,nombre,icono')
            ->orderByDesc('inicio')
            ->get();

        return response()->json(['cliente' => $cliente->nombre_completo, 'citas' => $citas]);
    }

    /** El cliente cancela su propia cita (verificando el correo). */
    public function cancelar(Request $request, Cita $cita)
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        if (! $cita->cliente || strtolower($cita->cliente->email) !== strtolower($data['email'])) {
            return response()->json(['message' => 'No autorizado para cancelar esta cita.'], 403);
        }
        if (in_array($cita->estado, ['CANCELADA', 'COMPLETADA'])) {
            return response()->json(['message' => 'La cita no se puede cancelar.'], 422);
        }

        $cita->update(['estado' => 'CANCELADA']);
        return response()->json(['mensaje' => 'Cita cancelada.']);
    }
}
