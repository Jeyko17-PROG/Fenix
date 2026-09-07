<?php

namespace App\Http\Middleware;

use App\Support\Numero;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Red de seguridad del backend contra números en formato colombiano:
 * convierte "400.000" -> "400000" y "400,5" -> "400.5" en los campos
 * monetarios/cuantitativos ANTES de la validación, para que el sistema
 * no trunque los miles (bug: factura de $400.000 calculada como $400).
 *
 * Solo toca campos cuyo nombre es claramente numérico (precio, costo,
 * monto, cantidad...) para no alterar textos ni números de documento.
 */
class NormalizarNumerosLocales
{
    /** Fragmentos de nombre de campo que se consideran numéricos. */
    private const CAMPOS_NUMERICOS = [
        'precio', 'costo', 'cantidad', 'monto', 'total', 'subtotal', 'impuesto',
        'valor', 'saldo', 'comision', 'stock', 'anticipo', 'exchange_rate',
        'descuento', 'limite', 'credits', 'price', 'km_', 'propina',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $datos = $request->all();
        if (! empty($datos)) {
            $normalizados = $this->normalizarArreglo($datos);
            $request->replace($normalizados);
        }

        return $next($request);
    }

    private function normalizarArreglo(array $datos): array
    {
        foreach ($datos as $clave => $valor) {
            if (is_array($valor)) {
                $datos[$clave] = $this->normalizarArreglo($valor);
            } elseif (is_string($valor) && $this->esCampoNumerico((string) $clave)) {
                $datos[$clave] = Numero::normalizar($valor);
            }
        }
        return $datos;
    }

    private function esCampoNumerico(string $clave): bool
    {
        $clave = strtolower($clave);
        foreach (self::CAMPOS_NUMERICOS as $fragmento) {
            if (str_contains($clave, $fragmento)) {
                return true;
            }
        }
        return false;
    }
}
