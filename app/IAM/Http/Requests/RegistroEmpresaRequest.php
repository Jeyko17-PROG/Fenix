<?php

namespace App\IAM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Registro público de un nuevo usuario + su empresa (tenant).
 *
 * OJO: el formulario de registro (Login.jsx) hoy NO recolecta `direccion`,
 * así que no se exige aquí — hacerlo rompería el alta de cuentas en
 * producción hasta agregar el campo en el frontend. El número/tipo de
 * documento que llega es el de la PERSONA que se registra (no
 * necesariamente el NIT del negocio, que se completa después desde el
 * panel de empresa).
 *
 * El documento es obligatorio y se valida con el formato real de cada tipo
 * (no basta con "algo relleno"): cédulas y cédulas de extranjería solo
 * dígitos, pasaportes alfanuméricos, y NIT con su dígito de verificación
 * (algoritmo módulo 11 de la DIAN) cuando viene incluido como "900123456-7".
 */
class RegistroEmpresaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ruta pública de registro, sin restricción de rol
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:100', 'regex:/^[\pL\d\s.\'\-]+$/u'],
            'tipo_documento' => ['required', 'in:CC,CE,NIT,PAS'],
            'numero_documento' => ['required', 'string', 'min:5', 'max:20'],
            'telefono' => ['nullable', 'string', 'regex:/^[0-9+\s\-]{7,20}$/'],
            'email' => ['required', 'email:rfc,dns', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'nombre_empresa' => ['nullable', 'string', 'min:3', 'max:100', 'regex:/^[\pL\d\s.\'\-&]+$/u'],
            'tipo_negocio_id' => ['required', 'exists:tipos_negocio,id'],
            // "Otro" en el selector de tipo de negocio necesita que la persona diga
            // a qué se dedica de verdad (médico, tecnológico, un local común...),
            // en vez de quedar como un cajón genérico sin describir.
            'tipo_negocio_otro' => ['required_if:tipo_negocio_id,' . self::idTipoNegocioOtro(), 'nullable', 'string', 'min:3', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'tipo_negocio_id.required' => 'Selecciona el tipo de negocio.',
            'tipo_negocio_otro.required_if' => 'Cuéntanos a qué se dedica tu negocio (ej: consultorio médico, tienda de tecnología...).',
            'name.regex' => 'El nombre solo puede contener letras, números, espacios y . \' -',
            'nombre_empresa.regex' => 'El nombre del negocio solo puede contener letras, números, espacios y . \' - &',
            'telefono.regex' => 'Ingresa un teléfono válido (7 a 20 dígitos).',
            'tipo_documento.required' => 'Selecciona el tipo de documento.',
            'numero_documento.required' => 'Ingresa tu número de documento.',
            'email.email' => 'Ingresa un correo electrónico válido y existente.',
        ];
    }

    /** Id del tipo de negocio "otro" del catálogo, o -1 (inexistente) si aún no se ha creado. */
    private static function idTipoNegocioOtro(): int
    {
        return (int) (\App\Business\Infrastructure\Persistence\Eloquent\TipoNegocio::where('clave', 'otro')->value('id') ?? -1);
    }

    /**
     * Valida el FORMATO real del documento según su tipo - no basta con que
     * el campo no esté vacío. Se hace aquí (no con `regex:` en rules()) porque
     * el patrón depende del valor de `tipo_documento`, otro campo del mismo
     * formulario.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $tipo = $this->input('tipo_documento');
            $numero = trim((string) $this->input('numero_documento', ''));
            if ($numero === '' || ! in_array($tipo, ['CC', 'CE', 'NIT', 'PAS'], true)) {
                return; // ya lo reporta 'required'/'in' de rules()
            }

            match ($tipo) {
                'CC' => $this->validarCedula($validator, $numero, 'cédula de ciudadanía', 6, 10),
                'CE' => $this->validarCedula($validator, $numero, 'cédula de extranjería', 6, 10),
                'PAS' => $this->validarPasaporte($validator, $numero),
                'NIT' => $this->validarNit($validator, $numero),
                default => null,
            };
        });
    }

    private function validarCedula(Validator $validator, string $numero, string $etiqueta, int $min, int $max): void
    {
        if (! preg_match('/^[0-9]+$/', $numero)) {
            $validator->errors()->add('numero_documento', "La {$etiqueta} solo debe tener números, sin puntos ni espacios.");
            return;
        }
        if (strlen($numero) < $min || strlen($numero) > $max) {
            $validator->errors()->add('numero_documento', "La {$etiqueta} debe tener entre {$min} y {$max} dígitos.");
        }
    }

    private function validarPasaporte(Validator $validator, string $numero): void
    {
        if (! preg_match('/^[A-Za-z0-9]{6,9}$/', $numero)) {
            $validator->errors()->add('numero_documento', 'El número de pasaporte debe tener entre 6 y 9 caracteres (letras y números).');
        }
    }

    /**
     * NIT colombiano: 9 a 15 dígitos base, opcionalmente seguido de "-D" con
     * el dígito de verificación real (algoritmo módulo 11 de la DIAN). Si no
     * lo escriben, se exige al menos el número base con un formato sensato;
     * si SÍ lo escriben, se verifica que el dígito sea matemáticamente
     * correcto - así no se cuela un NIT inventado con un "-0" al final.
     */
    private function validarNit(Validator $validator, string $numero): void
    {
        if (! preg_match('/^([0-9]{5,15})(?:-([0-9]))?$/', $numero, $m)) {
            $validator->errors()->add('numero_documento', 'El NIT debe ser numérico (opcionalmente con el dígito de verificación, ej: 900123456-7).');
            return;
        }

        if (isset($m[2]) && self::digitoVerificacionNit($m[1]) !== (int) $m[2]) {
            $validator->errors()->add('numero_documento', 'El dígito de verificación del NIT no es correcto. Verifícalo en el RUT.');
        }
    }

    /** Dígito de verificación de un NIT colombiano (algoritmo módulo 11 de la DIAN). */
    public static function digitoVerificacionNit(string $nitBase): int
    {
        $pesos = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];
        $digitos = array_reverse(str_split($nitBase));

        $suma = 0;
        foreach ($digitos as $i => $digito) {
            $suma += ((int) $digito) * ($pesos[$i] ?? 0);
        }

        $residuo = $suma % 11;

        return $residuo < 2 ? $residuo : 11 - $residuo;
    }
}
