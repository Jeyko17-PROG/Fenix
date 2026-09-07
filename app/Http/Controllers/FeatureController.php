<?php

namespace App\Http\Controllers;

use App\Support\Funcionalidades;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeatureController extends Controller
{
    /**
     * Funcionalidades del usuario autenticado (las usa el frontend para
     * ocultar módulos desactivados y marcar los restringidos).
     */
    public function mias(Request $request): JsonResponse
    {
        return response()->json([
            'catalogo' => Funcionalidades::CATALOGO,
            'funcionalidades' => Funcionalidades::mapaEfectivo($request->user()),
        ]);
    }
}
