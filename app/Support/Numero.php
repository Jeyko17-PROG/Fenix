<?php

namespace App\Support;

/**
 * Normaliza números escritos en formato colombiano (es-CO) a formato estándar:
 *   "400.000"      -> "400000"   (punto = separador de miles)
 *   "1.200.000"    -> "1200000"
 *   "1.200.000,50" -> "1200000.50"
 *   "400,5"        -> "400.5"    (coma = separador decimal)
 *   "0.500"        -> "0.500"    (parte entera 0: se respeta como decimal)
 *   "400.50"       -> "400.50"   (1-2 dígitos tras el punto: decimal normal)
 *
 * Solo transforma cadenas que claramente son números con formato local;
 * cualquier otro valor se devuelve intacto.
 */
class Numero
{
    public static function normalizar(mixed $valor): mixed
    {
        if (! is_string($valor)) {
            return $valor;
        }

        $s = trim($valor);
        if ($s === '') {
            return $valor;
        }

        // 1.200.000 ó 1.200.000,50 — puntos de miles (con o sin decimales en coma).
        if (preg_match('/^-?(\d{1,3})((?:\.\d{3})+)(,\d{1,2})?$/', $s, $m)) {
            // Excepción: "0.500" (un solo grupo con parte entera 0) es decimal, no miles.
            if ($m[1] === '0' && substr_count($m[2], '.') === 1 && empty($m[3])) {
                return $valor;
            }
            return str_replace(',', '.', str_replace('.', '', $s));
        }

        // 400,5 ó 1200,75 — coma decimal simple.
        if (preg_match('/^-?\d+,\d{1,2}$/', $s)) {
            return str_replace(',', '.', $s);
        }

        // Caso ambiguo "400.000": UN punto seguido de exactamente 3 dígitos.
        // En pesos colombianos esto es un separador de miles (no 400 con 3 decimales),
        // salvo que la parte entera sea 0 ("0.500" = medio, se respeta).
        if (preg_match('/^-?0*[1-9]\d*\.\d{3}$/', $s)) {
            return str_replace('.', '', $s);
        }

        return $valor;
    }
}
