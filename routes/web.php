<?php

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

// Sirve la SPA de React (resources/frontend) para cualquier ruta que no sea
// /api, /storage o /build — el enrutamiento real de página a página lo hace
// react-router-dom en el navegador. Sin Blade: SpaController genera el HTML
// directo en PHP (ver app/Http/Controllers/SpaController.php).
// El negative lookahead exige el segmento completo (seguido de "/" o fin de
// string), no solo el prefijo: así "/apidocs" o "/buildings" sí caen en la SPA
// en vez de quedar excluidos por empezar con las mismas letras que "api"/"build".
// "up" excluido para no tapar el healthcheck nativo de Laravel (bootstrap/app.php: health: '/up').
Route::get('/{any?}', [SpaController::class, 'index'])->where('any', '^(?!(?:api|storage|build|up)(?:/|$)).*$');
