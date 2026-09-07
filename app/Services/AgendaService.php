<?php

namespace App\Services;

use App\Models\AjusteAgenda;
use App\Models\BloqueoAgenda;
use App\Models\Cita;
use App\Models\HorarioLaboral;
use App\Models\Scopes\OwnerScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Lógica de la agenda: disponibilidad en tiempo real y prevención de doble reserva.
 *
 * Multisucursal: cada sucursal (bodega) tiene su propio calendario de CITAS
 * (dos sedes pueden tener una cita a la misma hora sin chocar). El horario
 * laboral y los bloqueos son por defecto de TODA la empresa (bodega_id nulo),
 * pero una sucursal puede definir los suyos propios, que tienen prioridad
 * sobre el horario/bloqueos generales cuando existen.
 */
class AgendaService
{
    /**
     * Calcula los horarios (slots) disponibles para una fecha dada.
     *
     * @return array<int, array{inicio:string, fin:string, disponible:bool}>
     */
    public function slotsDisponibles(Carbon $fecha, int $duracionMin, ?int $ownerId = null, ?int $bodegaId = null, ?int $operablesEmployeeId = null): array
    {
        $ownerId ??= Auth::id();
        $ajustes = AjusteAgenda::actual($ownerId);
        $buffer = $ajustes->buffer_min;

        $diaSemana = (int) $fecha->dayOfWeek; // 0=domingo
        $horarios = $this->horariosDelDia($diaSemana, $ownerId, $bodegaId);

        // Si el negocio aún no configuró horarios, usa una jornada por defecto (08:00–18:00)
        // para que la agenda funcione desde el primer día.
        if ($horarios->isEmpty() && $this->sinHorarios($ownerId)) {
            $horarios = collect([new HorarioLaboral(['hora_inicio' => '08:00:00', 'hora_fin' => '18:00:00'])]);
        }

        // Si el cliente eligió un especialista, el "ocupado" es SOLO de esa
        // persona (dos barberos pueden atender a la vez en el mismo horario).
        $citas = $this->citasDelDia($fecha, $ownerId, $bodegaId, $operablesEmployeeId);
        $bloqueos = $this->bloqueosDelDia($fecha, $ownerId, $bodegaId);

        $slots = [];
        foreach ($horarios as $horario) {
            $cursor = $fecha->copy()->setTimeFromTimeString($horario->hora_inicio);
            $finJornada = $fecha->copy()->setTimeFromTimeString($horario->hora_fin);

            while ($cursor->copy()->addMinutes($duracionMin)->lte($finJornada)) {
                $slotInicio = $cursor->copy();
                $slotFin = $cursor->copy()->addMinutes($duracionMin);

                $disponible = ! $this->seSolapaConCitas($slotInicio, $slotFin, $citas)
                    && ! $this->seSolapaConBloqueos($slotInicio, $slotFin, $bloqueos)
                    && $slotInicio->isFuture();

                $slots[] = [
                    'inicio' => $slotInicio->toIso8601String(),
                    'fin' => $slotFin->toIso8601String(),
                    'disponible' => $disponible,
                ];

                $cursor->addMinutes($duracionMin + $buffer);
            }
        }

        return $slots;
    }

    /**
     * Verifica que un rango sea reservable; lanza ValidationException si no.
     * Esta es la garantía contra la DOBLE RESERVA.
     */
    public function asegurarDisponible(Carbon $inicio, Carbon $fin, ?int $ignorarCitaId = null, ?int $ownerId = null, ?int $bodegaId = null, ?int $operablesEmployeeId = null): void
    {
        $ownerId ??= Auth::id();

        if ($fin->lte($inicio)) {
            throw ValidationException::withMessages(['fin' => ['La hora de fin debe ser posterior al inicio.']]);
        }

        // ¿Dentro del horario laboral del día?
        // Si el negocio NO tiene horarios configurados, no se restringe por jornada
        // (un usuario nuevo debe poder agendar sin configurar la agenda primero).
        if (! $this->sinHorarios($ownerId)) {
            $diaSemana = (int) $inicio->dayOfWeek;
            $dentroJornada = $this->horariosDelDia($diaSemana, $ownerId, $bodegaId)
                ->contains(function ($h) use ($inicio, $fin) {
                    $hi = $inicio->copy()->setTimeFromTimeString($h->hora_inicio);
                    $hf = $inicio->copy()->setTimeFromTimeString($h->hora_fin);
                    return $inicio->gte($hi) && $fin->lte($hf);
                });

            if (! $dentroJornada) {
                throw ValidationException::withMessages(['inicio' => ['El horario está fuera de la jornada laboral.']]);
            }
        }

        // ¿Choca con un bloqueo? (general de la empresa o propio de la sucursal)
        if ($this->seSolapaConBloqueos($inicio, $fin, $this->bloqueosDelDia($inicio, $ownerId, $bodegaId))) {
            throw ValidationException::withMessages(['inicio' => ['Ese horario está bloqueado.']]);
        }

        // ¿Choca con otra cita activa? (doble reserva; scoped por sucursal y,
        // si se indica, por el especialista elegido)
        $citas = $this->citasDelDia($inicio, $ownerId, $bodegaId, $operablesEmployeeId)->reject(fn ($c) => $c->id === $ignorarCitaId);
        if ($this->seSolapaConCitas($inicio, $fin, $citas)) {
            throw ValidationException::withMessages(['inicio' => ['Ya existe una cita en ese horario.']]);
        }
    }

    /** ¿El negocio aún no tiene NINGÚN horario laboral configurado (en ninguna sucursal)? */
    private function sinHorarios(?int $ownerId): bool
    {
        return HorarioLaboral::withoutGlobalScope(OwnerScope::class)
            ->where('owner_id', $ownerId)
            ->where('activo', true)
            ->count() === 0;
    }

    /**
     * Horario laboral de un día: si la sucursal tiene el suyo propio, ese manda;
     * si no, cae al horario general de la empresa (bodega_id nulo).
     */
    private function horariosDelDia(int $diaSemana, ?int $ownerId, ?int $bodegaId)
    {
        $base = HorarioLaboral::withoutGlobalScope(OwnerScope::class)
            ->where('owner_id', $ownerId)
            ->where('dia_semana', $diaSemana)->where('activo', true);

        if ($bodegaId) {
            $propios = (clone $base)->where('bodega_id', $bodegaId)->get();
            if ($propios->isNotEmpty()) {
                return $propios;
            }
        }

        return $base->whereNull('bodega_id')->get();
    }

    private function citasDelDia(Carbon $fecha, ?int $ownerId, ?int $bodegaId = null, ?int $operablesEmployeeId = null)
    {
        return Cita::withoutGlobalScope(OwnerScope::class)
            ->where('owner_id', $ownerId)
            ->when($bodegaId, fn ($q) => $q->where('bodega_id', $bodegaId))
            // Con especialista elegido, el choque de horario es SOLO contra SUS
            // propias citas (otro profesional puede atender en simultáneo).
            ->when($operablesEmployeeId, fn ($q) => $q->where('operables_employee_id', $operablesEmployeeId))
            ->whereDate('inicio', $fecha->toDateString())
            ->whereIn('estado', Cita::ESTADOS_ACTIVOS)
            ->get(['id', 'inicio', 'fin']);
    }

    /** Bloqueos vigentes ese día: los generales de la empresa + los propios de la sucursal (si se indica). */
    private function bloqueosDelDia(Carbon $fecha, ?int $ownerId, ?int $bodegaId = null)
    {
        return BloqueoAgenda::withoutGlobalScope(OwnerScope::class)
            ->where('owner_id', $ownerId)
            ->when(
                $bodegaId,
                fn ($q) => $q->where(fn ($w) => $w->whereNull('bodega_id')->orWhere('bodega_id', $bodegaId)),
                fn ($q) => $q->whereNull('bodega_id'),
            )
            ->whereDate('inicio', '<=', $fecha->toDateString())
            ->whereDate('fin', '>=', $fecha->toDateString())
            ->get();
    }

    private function seSolapaConCitas(Carbon $inicio, Carbon $fin, $citas): bool
    {
        return $citas->contains(fn ($c) => $inicio->lt($c->fin) && $fin->gt($c->inicio));
    }

    private function seSolapaConBloqueos(Carbon $inicio, Carbon $fin, $bloqueos): bool
    {
        return $bloqueos->contains(fn ($b) => $inicio->lt($b->fin) && $fin->gt($b->inicio));
    }
}
