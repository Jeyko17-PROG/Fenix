<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edición de una empresa desde el panel de Super-Admin
 * (EmpresaAdminController::update, ruta protegida por middleware 'superadmin').
 *
 * A diferencia del registro público, aquí SÍ se exige información completa
 * (dirección, documento único): es el super-admin curando el registro del
 * tenant, no un usuario llenando un alta rápida sin esos campos disponibles.
 */
class ActualizarEmpresaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la ruta ya está protegida por el middleware 'superadmin'
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'min:3', 'max:100', 'regex:/^[\pL\d\s.\'\-&]+$/u'],
            'tipo_documento' => ['required', 'in:CC,CE,NIT,PAS'],
            'numero_documento' => [
                'required', 'string', 'min:8', 'max:20', 'regex:/^[0-9\-]+$/',
                Rule::unique('empresas', 'numero_documento')->ignore($this->route('empresa')),
            ],
            'telefono' => ['required', 'string', 'regex:/^[0-9+\s\-]{7,20}$/'],
            'email' => ['required', 'email:rfc,dns'],
            'email_facturacion' => ['nullable', 'email:rfc,dns'],
            'direccion' => ['required', 'string', 'min:10', 'max:255'],
            'modo_cobro' => ['nullable', 'in:membresia,prepago'],
            'membresia_vence_at' => ['nullable', 'date'],
            'tipo_negocio_id' => ['required', 'exists:tipos_negocio,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'numero_documento.unique' => 'Ya existe otra empresa registrada con ese número de documento.',
            'nombre.regex' => 'El nombre solo puede contener letras, números, espacios y . \' - &',
            'telefono.regex' => 'Ingresa un teléfono válido (7 a 20 dígitos).',
            'numero_documento.regex' => 'El número de documento solo puede contener dígitos (y guion para NIT).',
            'direccion.min' => 'La dirección parece incompleta (mínimo 10 caracteres).',
        ];
    }
}
