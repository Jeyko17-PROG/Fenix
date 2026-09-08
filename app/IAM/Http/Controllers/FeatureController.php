<?php

namespace App\IAM\Http\Controllers;

use App\Shared\Http\Controllers\Controller;
use App\IAM\Application\Funcionalidades;
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
