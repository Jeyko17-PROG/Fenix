<?php

namespace Tests\Feature;

use App\Business\Infrastructure\Persistence\Eloquent\TipoNegocio;
use App\IAM\Infrastructure\Persistence\Eloquent\User;
use App\Shared\Infrastructure\Mail\CorreoLogix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * El registro público ya no depende de que un asesor le entregue el código
 * de activación a mano: se envía solo por correo al crear la cuenta, y el
 * usuario puede pedir que se lo reenvíen si no le llegó (self-service).
 */
class RegistroActivacionTest extends TestCase
{
    use RefreshDatabase;

    private function payloadRegistro(string $email): array
    {
        $tipo = TipoNegocio::firstOrCreate(['clave' => 'otro'], ['nombre' => 'Otro', 'activo' => true, 'orden' => 0]);

        return [
            'name' => 'Ana Pérez',
            'email' => $email,
            'password' => 'clave12345',
            'password_confirmation' => 'clave12345',
            'nombre_empresa' => 'Negocio de Ana',
            'tipo_negocio_id' => $tipo->id,
            'tipo_negocio_otro' => 'Negocio de prueba',
            'tipo_documento' => 'CC',
            'numero_documento' => '1020304050',
        ];
    }

    public function test_el_registro_envia_el_codigo_de_activacion_por_correo_automaticamente(): void
    {
        Mail::fake();

        $email = 'ana-' . uniqid() . '@gmail.com';
        $r = $this->postJson('/api/register', $this->payloadRegistro($email));

        $r->assertStatus(201)
            ->assertJsonPath('pendiente_activacion', true)
            ->assertJsonFragment(['message' => 'Tu cuenta fue creada. Revisa tu correo: te enviamos tu código de activación de 6 dígitos para poder ingresar.']);

        $user = User::where('email', $email)->firstOrFail();
        $this->assertSame('PENDIENTE_ACTIVACION', $user->estado);
        $this->assertNotEmpty($user->codigo_activacion);

        // Síncrono (no encolado): el usuario está esperando este correo en
        // pantalla, no debe depender de que un worker de colas esté corriendo.
        Mail::assertSent(CorreoLogix::class, function (CorreoLogix $mail) use ($email, $user) {
            return $mail->hasTo($email) && in_array($user->codigo_activacion, $mail->lineas, true);
        });
    }

    public function test_reenviar_codigo_genera_uno_nuevo_y_lo_envia_por_correo(): void
    {
        Mail::fake();

        $email = 'reenvio-' . uniqid() . '@gmail.com';
        $this->postJson('/api/register', $this->payloadRegistro($email))->assertStatus(201);

        $user = User::where('email', $email)->firstOrFail();
        $codigoOriginal = $user->codigo_activacion;
        // Simula intentos fallidos previos: reenviar debe resetear el contador.
        $user->forceFill(['codigo_activacion_intentos' => 3])->save();

        Mail::fake(); // limpia lo enviado durante el registro, solo interesa este reenvío

        $r = $this->postJson('/api/reenviar-codigo-activacion', ['email' => $email]);
        $r->assertStatus(200);

        $user->refresh();
        $this->assertNotSame($codigoOriginal, $user->codigo_activacion);
        $this->assertSame(0, $user->codigo_activacion_intentos);

        Mail::assertSent(CorreoLogix::class, function (CorreoLogix $mail) use ($email, $user) {
            return $mail->hasTo($email) && in_array($user->codigo_activacion, $mail->lineas, true);
        });
    }

    public function test_reenviar_codigo_no_revela_si_el_correo_existe(): void
    {
        Mail::fake();

        $r = $this->postJson('/api/reenviar-codigo-activacion', ['email' => 'nadie-' . uniqid() . '@gmail.com']);

        $r->assertStatus(200)
            ->assertJsonPath('message', 'Si el correo tiene una cuenta pendiente de activación, te enviamos un nuevo código.');

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_crear_negocio_vinculado_no_envia_codigo_automatico_al_alias_interno(): void
    {
        Mail::fake();

        $email = 'dueno-' . uniqid() . '@gmail.com';
        $this->postJson('/api/register', $this->payloadRegistro($email))->assertStatus(201);
        $dueno = User::where('email', $email)->firstOrFail();
        $dueno->activarPendiente();

        Mail::fake(); // solo interesa lo que pase al crear el segundo negocio

        $tipo = TipoNegocio::where('clave', 'otro')->first();
        $this->actingAs($dueno, 'sanctum')->postJson('/api/cuenta/nuevo-negocio', [
            'nombre_empresa' => 'Segundo Negocio de Ana',
            'tipo_negocio_id' => $tipo->id,
        ])->assertStatus(201);

        // El correo interno (alias +tag del dueño) no debe recibir el código
        // automático: ese negocio sigue exigiendo activación manual del super-admin.
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }
}
