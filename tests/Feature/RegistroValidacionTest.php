<?php

namespace Tests\Feature;

use App\Business\Infrastructure\Persistence\Eloquent\Empresa;
use App\Business\Infrastructure\Persistence\Eloquent\TipoNegocio;
use App\IAM\Http\Requests\RegistroEmpresaRequest;
use App\IAM\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El registro ahora exige un documento con formato real por tipo (no "algo
 * relleno"), y si el negocio es "Otro" pide describir a qué se dedica.
 */
class RegistroValidacionTest extends TestCase
{
    use RefreshDatabase;

    private function tipoNegocio(string $clave = 'taller_motos'): TipoNegocio
    {
        return TipoNegocio::firstOrCreate(['clave' => $clave], ['nombre' => ucfirst($clave), 'activo' => true, 'orden' => 0]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Carlos Ruiz',
            'email' => 'carlos-' . uniqid() . '@gmail.com',
            'password' => 'clave12345',
            'password_confirmation' => 'clave12345',
            'nombre_empresa' => 'Taller de Carlos',
            'tipo_negocio_id' => $this->tipoNegocio()->id,
            'tipo_documento' => 'CC',
            'numero_documento' => '1020304050',
        ], $overrides);
    }

    public function test_rechaza_registro_sin_documento(): void
    {
        $data = $this->payload(['numero_documento' => '']);
        $this->postJson('/api/register', $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors('numero_documento');
    }

    public function test_rechaza_cedula_con_letras(): void
    {
        $this->postJson('/api/register', $this->payload(['numero_documento' => '10A2030']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('numero_documento');
    }

    public function test_acepta_cedula_valida(): void
    {
        $this->postJson('/api/register', $this->payload(['numero_documento' => '1020304050']))
            ->assertStatus(201);
    }

    public function test_rechaza_pasaporte_demasiado_corto(): void
    {
        $this->postJson('/api/register', $this->payload(['tipo_documento' => 'PAS', 'numero_documento' => 'AB12']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('numero_documento');
    }

    public function test_acepta_pasaporte_alfanumerico_valido(): void
    {
        $this->postJson('/api/register', $this->payload(['tipo_documento' => 'PAS', 'numero_documento' => 'AB123456']))
            ->assertStatus(201);
    }

    public function test_acepta_nit_sin_digito_de_verificacion(): void
    {
        $this->postJson('/api/register', $this->payload(['tipo_documento' => 'NIT', 'numero_documento' => '900123456']))
            ->assertStatus(201);
    }

    public function test_rechaza_nit_con_digito_de_verificacion_incorrecto(): void
    {
        $dv = RegistroEmpresaRequest::digitoVerificacionNit('900123456');
        $dvIncorrecto = ($dv + 1) % 10; // seguro que no coincide con el real

        $this->postJson('/api/register', $this->payload([
            'tipo_documento' => 'NIT',
            'numero_documento' => "900123456-{$dvIncorrecto}",
        ]))->assertStatus(422)->assertJsonValidationErrors('numero_documento');
    }

    public function test_acepta_nit_con_digito_de_verificacion_correcto(): void
    {
        $dv = RegistroEmpresaRequest::digitoVerificacionNit('900123456');

        $this->postJson('/api/register', $this->payload([
            'tipo_documento' => 'NIT',
            'numero_documento' => "900123456-{$dv}",
        ]))->assertStatus(201);
    }

    public function test_exige_describir_el_negocio_cuando_el_tipo_es_otro(): void
    {
        $otro = $this->tipoNegocio('otro');

        $this->postJson('/api/register', $this->payload(['tipo_negocio_id' => $otro->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('tipo_negocio_otro');
    }

    public function test_guarda_la_descripcion_personalizada_del_negocio_otro(): void
    {
        $otro = $this->tipoNegocio('otro');
        $email = 'medico-' . uniqid() . '@gmail.com';

        $this->postJson('/api/register', $this->payload([
            'email' => $email,
            'tipo_negocio_id' => $otro->id,
            'tipo_negocio_otro' => 'Consultorio médico',
        ]))->assertStatus(201);

        $user = User::where('email', $email)->firstOrFail();
        $empresa = Empresa::where('owner_user_id', $user->id)->firstOrFail();
        $this->assertSame('Consultorio médico', $empresa->tipo_negocio_otro);
    }
}
