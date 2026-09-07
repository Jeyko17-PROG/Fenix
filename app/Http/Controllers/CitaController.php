<?php

namespace App\Http\Controllers;

use App\Models\AjusteAgenda;
use App\Models\Cita;
use App\Models\Servicio;
use App\Models\User;
use App\Services\AgendaService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CitaController extends Controller
{
    public function __construct(private AgendaService $agenda) {}

    /**
     * Lista citas en un rango (para vistas día/semana/mes).
     */
    public function index(Request $request)
    {
        $q = Cita::with(['cliente:id,nombre_completo', 'servicio:id,nombre,duracion_min,icono', 'empleado:id,name', 'planLavado:id,nombre,precio,icono', 'bodega:id,nombre', 'detalleServicios.servicio:id,nombre,icono']);

        // Rol Lavador: solo ve las citas que tiene asignadas.
        $this->limitarALavador($request, $q);

        if ($desde = $request->query('desde')) {
            $q->where('inicio', '>=', Carbon::parse($desde)->startOfDay());
        }
        if ($hasta = $request->query('hasta')) {
            $q->where('inicio', '<=', Carbon::parse($hasta)->endOfDay());
        }
        if ($cliente = $request->query('cliente_id')) {
            $q->where('cliente_id', $cliente);
        }

        return $q->orderBy('inicio')->get();
    }

    /**
     * Horarios disponibles para una fecha (disponibilidad en tiempo real).
     */
    public function disponibilidad(Request $request)
    {
        $data = $request->validate([
            'fecha' => ['required', 'date'],
            'servicio_id' => ['nullable', 'exists:servicios,id'],
            'bodega_id' => ['nullable', 'exists:bodegas,id'],
            // Cuando la cita tiene varios servicios, el frontend suma sus duraciones
            // y la manda aquí directamente en vez de un servicio_id único.
            'duracion_min' => ['nullable', 'integer', 'min:1'],
        ]);

        $duracion = $data['duracion_min'] ?? $this->duracionPara($data['servicio_id'] ?? null);
        $slots = $this->agenda->slotsDisponibles(Carbon::parse($data['fecha']), $duracion, null, $data['bodega_id'] ?? null);

        return response()->json(['duracion_min' => $duracion, 'slots' => $slots]);
    }

    /**
     * Personal del negocio (dueño + empleados activos) para asignar a una cita.
     * Sin bodega_id devuelve a todos; con bodega_id, a los sin sede fija más los de esa sede
     * (misma convención que el catálogo de servicios: sin sede asignada = disponible en todas).
     */
    public function personal(Request $request)
    {
        $bodegaId = $request->query('bodega_id');
        $ownerId = $request->user()->workspaceOwnerId();

        return User::query()
            ->where(fn ($q) => $q->where('id', $ownerId)->orWhere('workspace_owner_id', $ownerId))
            ->where('activo', true)
            ->when($bodegaId, fn ($q) => $q->where(fn ($w) => $w->whereNull('bodega_id')->orWhere('bodega_id', $bodegaId)))
            ->orderBy('name')
            ->get(['id', 'name', 'bodega_id']);
    }

    public function store(Request $request)
    {
        // Restricción automática por plan: bloquea si se alcanzó el límite de citas.
        $user = $request->user();
        if (! $user->esSuperAdmin()) {
            $limite = $user->limiteCitasEfectivo();
            $usadas = $user->citasUsadas();
            if ($usadas >= $limite) {
                return response()->json([
                    'message' => 'Ha alcanzado el límite de citas de su plan. Actualice su plan para seguir agendando.',
                    'limite_alcanzado' => true,
                    'usados' => $usadas,
                    'limite' => $limite,
                ], 403);
            }
        }

        $data = $this->validar($request);
        $servicios = $data['servicios'] ?? null;
        unset($data['servicios']);
        $this->validarLineasServicio($servicios);

        $inicio = Carbon::parse($data['inicio']);
        $duracion = $servicios
            ? $this->duracionTotalServicios($servicios)
            : $this->duracionPara($data['servicio_id'] ?? null, $data['plan_lavado_id'] ?? null);
        $fin = $inicio->copy()->addMinutes($duracion);

        $this->agenda->asegurarDisponible($inicio, $fin, null, null, $data['bodega_id'] ?? null);

        // Con varias líneas, servicio_id (columna directa) queda con la primera
        // para que reportes/consumidores antiguos sigan viendo "un" servicio.
        if ($servicios && empty($data['servicio_id'])) {
            $data['servicio_id'] = $servicios[0]['servicio_id'] ?? null;
        }

        $cita = Cita::create([
            ...$data,
            'inicio' => $inicio,
            'fin' => $fin,
            'estado' => 'PENDIENTE',
            'origen' => 'ADMIN',
            'created_by' => $request->user()->id,
        ]);

        if ($servicios) {
            foreach ($servicios as $item) {
                $catalogo = ! empty($item['servicio_id']) ? Servicio::find($item['servicio_id']) : null;
                $cita->detalleServicios()->create([
                    'servicio_id' => $item['servicio_id'] ?? null,
                    'nombre_personalizado' => $item['nombre_personalizado'] ?? null,
                    'precio_unitario' => $item['precio_unitario'] ?? $catalogo?->precio ?? 0,
                    'duracion_min' => $item['duracion_min'] ?? $catalogo?->duracion_min ?? 0,
                ]);
            }
        }

        return response()->json($cita->load([
            'cliente:id,nombre_completo', 'servicio:id,nombre,icono', 'planLavado:id,nombre,icono',
            'bodega:id,nombre', 'detalleServicios.servicio:id,nombre,icono',
        ]), 201);
    }

    public function show(Cita $cita)
    {
        $this->autorizarLavador($cita);

        return $cita->load(['cliente', 'servicio', 'planLavado', 'empleado:id,name', 'bodega', 'detalleServicios.servicio:id,nombre,icono']);
    }

    public function update(Request $request, Cita $cita)
    {
        $this->autorizarLavador($cita);

        $data = $request->validate([
            'observaciones' => ['nullable', 'string'],
            'empleado_id' => ['nullable', 'exists:users,id'],
        ]);
        $cita->update($data);
        return $cita;
    }

    /** Reprogramar: cambia inicio/fin validando disponibilidad. */
    public function reprogramar(Request $request, Cita $cita)
    {
        $data = $request->validate(['inicio' => ['required', 'date']]);

        $inicio = Carbon::parse($data['inicio']);
        $fin = $inicio->copy()->addMinutes($this->duracionPara($cita->servicio_id, $cita->plan_lavado_id));

        $this->agenda->asegurarDisponible($inicio, $fin, $cita->id, null, $cita->bodega_id);

        $cita->update(['inicio' => $inicio, 'fin' => $fin, 'estado' => 'REPROGRAMADA']);
        return $cita;
    }

    public function confirmar(Cita $cita)
    {
        $cita->update(['estado' => 'CONFIRMADA']);
        return $cita;
    }

    public function cancelar(Cita $cita)
    {
        $cita->update(['estado' => 'CANCELADA']);
        return $cita;
    }

    private function duracionPara(?int $servicioId, ?int $planLavadoId = null): int
    {
        if ($planLavadoId && $plan = \App\Models\PlanLavado::find($planLavadoId)) {
            return $plan->duracion_min;
        }
        if ($servicioId && $servicio = Servicio::find($servicioId)) {
            return $servicio->duracion_min;
        }
        return AjusteAgenda::actual()->duracion_cita_min;
    }

    /** Suma la duración de cada línea de servicio (catálogo o personalizada). */
    private function duracionTotalServicios(array $servicios): int
    {
        $total = 0;
        foreach ($servicios as $item) {
            $min = $item['duracion_min'] ?? null;
            if (! $min && ! empty($item['servicio_id'])) {
                $min = Servicio::find($item['servicio_id'])?->duracion_min;
            }
            $total += (int) ($min ?? 0);
        }
        return $total ?: AjusteAgenda::actual()->duracion_cita_min;
    }

    /** Cada línea debe traer un servicio del catálogo o un nombre personalizado. */
    private function validarLineasServicio(?array $servicios): void
    {
        foreach ($servicios ?? [] as $i => $item) {
            if (empty($item['servicio_id']) && trim($item['nombre_personalizado'] ?? '') === '') {
                throw ValidationException::withMessages([
                    "servicios.$i" => 'Cada servicio debe ser del catálogo o traer un nombre personalizado.',
                ]);
            }
        }
    }

    /** Clave del tipo de negocio de la empresa del usuario autenticado. */
    private function tipoNegocio(Request $request): ?string
    {
        return $request->user()?->empresaDeCobro()?->tipoNegocio?->clave;
    }

    /**
     * Valida los datos de la cita. Placa y tipo de vehículo son obligatorios
     * solo para negocios que trabajan con vehículos (talleres, lavadero); en
     * los demás no se piden y el frontend los oculta.
     */
    private function validar(Request $request): array
    {
        $conVehiculo = in_array($this->tipoNegocio($request), ['taller_motos', 'taller_carros', 'taller_general', 'lavadero'], true);

        return $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id'],
            'servicio_id' => ['nullable', 'exists:servicios,id'],
            // Varios servicios en una misma cita (ej. Uñas + Pestañas); alternativa a servicio_id.
            'servicios' => ['nullable', 'array', 'min:1'],
            'servicios.*.servicio_id' => ['nullable', 'exists:servicios,id'],
            'servicios.*.nombre_personalizado' => ['nullable', 'string', 'max:255'],
            'servicios.*.precio_unitario' => ['nullable', 'numeric', 'min:0'],
            'servicios.*.duracion_min' => ['nullable', 'integer', 'min:1'],
            'plan_lavado_id' => ['nullable', 'exists:planes_lavado,id'],
            'empleado_id' => ['nullable', 'exists:users,id'],
            'bodega_id' => ['nullable', 'exists:bodegas,id'],
            'tipo_vehiculo' => [$conVehiculo ? 'required' : 'nullable', 'in:moto,carro'],
            'placa' => [$conVehiculo ? 'required' : 'nullable', 'string', 'max:20'],
            'inicio' => ['required', 'date'],
            'observaciones' => ['nullable', 'string'],
        ]);
    }

    /**
     * Si el usuario tiene rol Lavador, limita la consulta a las citas donde
     * es el empleado asignado.
     */
    private function limitarALavador(Request $request, Builder $query): void
    {
        $user = $request->user();
        if (! $user?->esLavador()) {
            return;
        }
        $query->where('empleado_id', $user->id);
    }

    /** Aborta con 403 si un Lavador intenta acceder a una cita que no tiene asignada. */
    private function autorizarLavador(Cita $cita): void
    {
        $user = request()->user();
        if (! $user?->esLavador()) {
            return;
        }
        if ($cita->empleado_id !== $user->id) {
            abort(403, 'Solo puedes ver las citas que tienes asignadas.');
        }
    }
}
