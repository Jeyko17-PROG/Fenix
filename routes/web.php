<?php

use Illuminate\Support\Facades\Route;

// Toda ruta que llegue hasta aquí devuelve el shell de la SPA de React
// (resources/views/welcome.blade.php); el ruteo real lo hace react-router-dom
// en el navegador.
//
// El catch-all no tapa nada porque Laravel resuelve en orden de registro y las
// rutas de este archivo se registran de ÚLTIMAS: /api/* y /up (healthcheck) ya
// quedaron registradas antes (ver bootstrap/app.php y la implementación de
// withRouting), y /storage/* y /build/* son archivos reales en public/ que el
// servidor web entrega sin llegar nunca a PHP.
Route::get('/', function () {
    return view('welcome');
});

Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '.*');
