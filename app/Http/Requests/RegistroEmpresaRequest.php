<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Registro público de un nuevo usuario + su empresa (tenant).
 *
 * OJO: el formulario de registro (Login.jsx) hoy NO recolecta `direccion`,
 * así que no se exige aquí — hacerlo rompería el alta de cuentas en
 * producción hasta agregar el campo en el frontend. El número/tipo de
 * documento que llega es el de la PERSONA que se registra (no
 * necesariamente el NIT del negocio, que se completa después desde el
 * panel de empresa), así que se valida el formato pero no se exige ni
 * se hace único aquí.
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
            'tipo_documento' => ['nullable', 'in:CC,CE,NIT,PAS'],
            'numero_documento' => ['nullable', 'string', 'min:6', 'max:20', 'regex:/^[0-9\-]+$/'],
            'telefono' => ['nullable', 'string', 'regex:/^[0-9+\s\-]{7,20}$/'],
            'email' => ['required', 'email:rfc,dns', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            // Multiempresa: nombre del negocio y su tipo (define los módulos visibles).
            'nombre_empresa' => ['nullable', 'string', 'min:3', 'max:100', 'regex:/^[\pL\d\s.\'\-&]+$/u'],
            'tipo_negocio_id' => ['required', 'exists:tipos_negocio,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'tipo_negocio_id.required' => 'Selecciona el tipo de negocio.',
            'name.regex' => 'El nombre solo puede contener letras, números, espacios y . \' -',
            'nombre_empresa.regex' => 'El nombre del negocio solo puede contener letras, números, espacios y . \' - &',
            'telefono.regex' => 'Ingresa un teléfono válido (7 a 20 dígitos).',
            'numero_documento.regex' => 'El número de documento solo puede contener dígitos (y guion para NIT).',
            'email.email' => 'Ingresa un correo electrónico válido y existente.',
        ];
    }
}
