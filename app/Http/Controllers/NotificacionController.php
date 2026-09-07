<?php

namespace App\Http\Controllers;

use App\Models\Notificacion;
use Illuminate\Http\Request;

class NotificacionController extends Controller
{
    /**
     * Notificaciones del usuario actual ÚNICAMENTE (aisladas por user_id).
     * Las notificaciones administrativas se crean con el user_id del super-admin,
     * por lo que solo él las ve. No existen notificaciones globales.
     */
    public function index(Request $request)
    {
        return Notificacion::where('user_id', $request->user()->id)
            ->latest()->limit(50)->get();
    }

    public function noLeidas(Request $request)
    {
        $count = Notificacion::where('user_id', $request->user()->id)
            ->where('leida', false)->count();

        return response()->json(['no_leidas' => $count]);
    }

    public function marcarLeidas(Request $request)
    {
        Notificacion::where('user_id', $request->user()->id)
            ->update(['leida' => true]);

        return response()->json(['mensaje' => 'Notificaciones marcadas como leídas.']);
    }
}
