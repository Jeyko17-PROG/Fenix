<?php

namespace App\Http\Controllers;

use App\Models\AjusteAgenda;
use App\Models\BloqueoAgenda;
use App\Models\HorarioLaboral;
use Illuminate\Http\Request;

class ConfiguracionAgendaController extends Controller
{
    /**
     * Devuelve toda la configuración de la agenda. Con `bodega_id` trae el
     * horario/bloqueos PROPIOS de esa sucursal; sin él, el horario general
     * de toda la empresa (bodega_id nulo).
     */
    public function index(Request $request)
    {
        $bodegaId = $request->query('bodega_id');

        return response()->json([
            'ajustes' => AjusteAgenda::actual(),
            'horarios' => HorarioLaboral::where('bodega_id', $bodegaId)->orderBy('dia_semana')->get(),
            'bloqueos' => BloqueoAgenda::where('bodega_id', $bodegaId)->orderBy('inicio')->get(),
        ]);
    }

    /** Actualiza duración y buffer entre citas (general, aplica a toda la empresa). */
    public function guardarAjustes(Request $request)
    {
        $data = $request->validate([
            'duracion_cita_min' => ['required', 'integer', 'min:5'],
            'buffer_min' => ['required', 'integer', 'min:0'],
        ]);
        $ajustes = AjusteAgenda::actual();
        $ajustes->update($data);
        return $ajustes;
    }

    /** Reemplaza el horario laboral semanal de la empresa (bodega_id vacío) o de una sucursal específica. */
    public function guardarHorarios(Request $request)
    {
        $data = $request->validate([
            'bodega_id' => ['nullable', 'exists:bodegas,id'],
            'horarios' => ['present', 'array'],
            'horarios.*.dia_semana' => ['required', 'integer', 'between:0,6'],
            'horarios.*.hora_inicio' => ['required', 'date_format:H:i'],
            'horarios.*.hora_fin' => ['required', 'date_format:H:i', 'after:horarios.*.hora_inicio'],
        ]);
        $bodegaId = $data['bodega_id'] ?? null;

        HorarioLaboral::where('bodega_id', $bodegaId)->delete();
        foreach ($data['horarios'] as $h) {
            HorarioLaboral::create([...$h, 'bodega_id' => $bodegaId, 'activo' => true]);
        }

        return HorarioLaboral::where('bodega_id', $bodegaId)->orderBy('dia_semana')->get();
    }

    /** Bloquea un rango de fechas, general (toda la empresa) o de una sucursal específica. */
    public function crearBloqueo(Request $request)
    {
        $data = $request->validate([
            'bodega_id' => ['nullable', 'exists:bodegas,id'],
            'inicio' => ['required', 'date'],
            'fin' => ['required', 'date', 'after:inicio'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);
        return response()->json(BloqueoAgenda::create($data), 201);
    }

    public function eliminarBloqueo(BloqueoAgenda $bloqueo)
    {
        $bloqueo->delete();
        return response()->json(['message' => 'Bloqueo eliminado.']);
    }
}
