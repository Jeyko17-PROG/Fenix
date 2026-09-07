<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegistroEmpresaRequest;
use App\Models\NegocioVinculado;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Services\Notificador;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Registro de un nuevo usuario (cuenta SaaS aislada).
     */
    public function register(RegistroEmpresaRequest $request): JsonResponse
    {
        [$user, $vinculados] = $this->crearNegocio($request->validated());

        // Sin token: la cuenta no puede usarse hasta que se active con el código.
        return response()->json([
            'pendiente_activacion' => true,
            'email' => $user->email,
            'message' => $vinculados > 0
                ? "Tu cuenta fue creada. Un asesor de Fénix te compartirá tu código de activación de 6 dígitos para poder ingresar. Como ya tenías otro negocio registrado con el mismo documento, al entrar podrás elegir cuál usar desde \"Mis negocios\"."
                : 'Tu cuenta fue creada. Un asesor de Fénix te compartirá tu código de activación de 6 dígitos para poder ingresar.',
        ], 201);
    }

    /**
     * Crea la cuenta (usuario dueño + empresa) de un negocio nuevo, pendiente
     * de activación por el super-admin. Compartido por el registro público
     * (`register`) y por "Mis negocios" → "Crear otro negocio"
     * (`CuentaController::nuevoNegocio`), que reutiliza esto para que un
     * dueño ya logueado pueda sumar otro negocio sin pasar por el formulario
     * público de nuevo.
     *
     * $data espera las mismas llaves que RegistroEmpresaRequest: name,
     * tipo_documento, numero_documento, telefono, email, password,
     * nombre_empresa, tipo_negocio_id.
     *
     * @return array{0: User, 1: int} el usuario creado y cuántos negocios
     *   existentes se le vincularon automáticamente (mismo documento).
     */
    public function crearNegocio(array $data): array
    {
        // Todo usuario nuevo es "Usuario": propietario de su propio espacio aislado.
        $rolId = Role::where('nombre', 'Usuario')->value('id')
            ?? Role::where('nombre', 'Administrador')->value('id');

        // Plan por defecto: Gratuito.
        $planId = Plan::where('nombre', 'Gratuito')->value('id');

        // Código de activación de 6 dígitos: solo lo ve el super-admin (panel de
        // Empresas). Sin él, la cuenta queda bloqueada desde el registro — ni
        // siquiera puede intentar iniciar sesión hasta que se active.
        $codigoActivacion = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user = User::create([
            'name' => $data['name'],
            'tipo_documento' => $data['tipo_documento'] ?? null,
            'numero_documento' => $data['numero_documento'] ?? null,
            'telefono' => $data['telefono'] ?? null,
            'email' => $data['email'],
            'password' => $data['password'], // hasheado por el cast
            'rol_id' => $rolId,
            'plan_id' => $planId,
            'activo' => false,
            'estado' => 'PENDIENTE_ACTIVACION',
            'codigo_activacion' => $codigoActivacion,
        ]);

        // La empresa es el tenant real: dueña de los datos, el plan y la membresía.
        // Arranca en modo 'prueba': el reloj de los 15 días gratis empieza a
        // correr cuando el usuario activa la cuenta y puede usarla de verdad.
        $empresa = \App\Models\Empresa::create([
            'nombre' => $data['nombre_empresa'] ?? $data['name'],
            'tipo_documento' => $data['tipo_documento'] ?? null,
            'numero_documento' => $data['numero_documento'] ?? null,
            'telefono' => $data['telefono'] ?? null,
            'email' => $data['email'],
            'tipo_negocio_id' => $data['tipo_negocio_id']
                ?? \App\Models\TipoNegocio::where('clave', 'otro')->value('id'),
            'owner_user_id' => $user->id,
            'plan_id' => $planId,
            'modo_cobro' => 'prueba',
            'estado' => 'ACTIVO',
            'activo' => true,
        ]);
        $user->forceFill(['empresa_id' => $empresa->id, 'es_admin_empresa' => true])->save();

        $this->prepararEspacioDeTrabajo($user);
        $this->notificarNuevoRegistro($user, $codigoActivacion);
        $this->darBienvenida($user);
        $vinculados = $this->vincularNegociosDelMismoDueno($user);

        return [$user, $vinculados];
    }

    /**
     * Si la persona ya tiene otro(s) negocio(s) registrados con el mismo
     * documento de identidad, los vincula automáticamente a la cuenta nueva
     * para que aparezcan juntos en "Mis negocios" al iniciar sesión. Es una
     * comodidad, no una verificación de identidad fuerte: solo aplica cuando
     * el registro trae número de documento (nunca por coincidencia de nombre
     * o teléfono) y no toca el aislamiento de datos de ningún negocio.
     */
    private function vincularNegociosDelMismoDueno(User $nuevo): int
    {
        if (empty($nuevo->numero_documento)) {
            return 0;
        }

        $otros = User::where('numero_documento', $nuevo->numero_documento)
            ->where('tipo_documento', $nuevo->tipo_documento)
            ->where('id', '!=', $nuevo->id)
            ->where('workspace_owner_id', null) // solo dueños de negocio, no empleados
            ->get();

        foreach ($otros as $otro) {
            NegocioVinculado::firstOrCreate(['user_id' => $nuevo->id, 'vinculado_user_id' => $otro->id]);
        }

        return $otros->count();
    }

    /**
     * Activa la cuenta con el código de 6 dígitos entregado por el
     * super-admin. Al activarse arranca la prueba gratuita de 15 días
     * (calendario) y se emite el token de sesión (queda logueado).
     */
    public function activar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'codigo' => ['required', 'string', 'size:6'],
        ]);

        $user = User::where('email', $data['email'])->where('estado', 'PENDIENTE_ACTIVACION')->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['No hay una cuenta pendiente de activación con ese correo.'],
            ]);
        }

        if ($user->codigo_activacion_intentos >= 5) {
            throw ValidationException::withMessages([
                'codigo' => ['Superaste el número de intentos permitidos. Contacta al administrador de Fénix para que te genere un nuevo código.'],
            ]);
        }

        if ($user->codigo_activacion !== $data['codigo']) {
            $user->increment('codigo_activacion_intentos');
            throw ValidationException::withMessages([
                'codigo' => ['El código de activación no es correcto.'],
            ]);
        }

        // Activa la cuenta y arranca el reloj de los 15 días gratis (no en el registro).
        $user->activarPendiente();
        $user->forceFill(['ultimo_acceso' => now(), 'veces_login' => 1])->save();

        $token = $this->crearToken($user, $request);

        return response()->json([
            'user' => $user->load('rol', 'plan'),
            'token' => $token,
        ]);
    }

    /** Provisiona la configuración inicial del nuevo inquilino (horarios y ajustes de agenda). */
    private function prepararEspacioDeTrabajo(User $user): void
    {
        // El registro ocurre sin sesión autenticada: el hook del trait no puede
        // resolver la empresa, así que se pasa empresa_id explícito.
        $empresaId = $user->empresa_id;

        // Horario laboral por defecto: Lunes(1) a Sábado(6), 08:00–18:00.
        foreach (range(1, 6) as $dia) {
            \App\Models\HorarioLaboral::create([
                'owner_id' => $user->id,
                'empresa_id' => $empresaId,
                'dia_semana' => $dia,
                'hora_inicio' => '08:00:00',
                'hora_fin' => '18:00:00',
                'activo' => true,
            ]);
        }

        // Ajustes por defecto de la agenda (duración de cita y buffer).
        \App\Models\AjusteAgenda::create([
            'owner_id' => $user->id,
            'empresa_id' => $empresaId,
            'duracion_cita_min' => 30,
            'buffer_min' => 0,
        ]);

        // Bodegas por defecto del inquilino (Principal queda como principal).
        foreach (['Principal' => true, 'Centro' => false, 'Norte' => false] as $nombre => $principal) {
            \App\Models\Bodega::create([
                'owner_id' => $user->id,
                'empresa_id' => $empresaId,
                'nombre' => $nombre,
                'activo' => true,
                'es_principal' => $principal,
            ]);
        }

        // Slug público único para su portal de reservas (QR personalizado).
        $user->generarReservasSlug();

        // Cliente genérico para ventas rápidas de mostrador (tiendas, restaurantes).
        \App\Models\Cliente::create([
            'owner_id' => $user->id,
            'empresa_id' => $empresaId,
            'nombre_completo' => 'Consumidor Final',
            'estado' => 'ACTIVO',
            'created_by' => $user->id,
        ]);

        // Planes por defecto según el tipo de negocio (editables en Configuración).
        $tipoClave = $user->empresa?->tipoNegocio?->clave;
        if ($tipoClave === 'lavadero') {
            foreach ([
                ['nombre' => 'Plan Básico', 'precio' => 20000, 'duracion_min' => 30],
                ['nombre' => 'Plan Especial', 'precio' => 30000, 'duracion_min' => 45],
                ['nombre' => 'Plan Premium (Full)', 'precio' => 40000, 'duracion_min' => 60],
            ] as $plan) {
                \App\Models\Servicio::create([
                    'owner_id' => $user->id,
                    'empresa_id' => $empresaId,
                    'nombre' => $plan['nombre'],
                    'precio' => $plan['precio'],
                    'duracion_min' => $plan['duracion_min'],
                    'activo' => true,
                ]);
            }
        }
    }

    /** Notificación de bienvenida para el propio usuario (solo él la ve). */
    private function darBienvenida(User $user): void
    {
        $mensaje = "Bienvenido a tu sistema de inventario, agenda y control Fénix. "
            . "Tu cuenta ha sido creada correctamente y ya puedes gestionar clientes, inventario, "
            . "productos, facturación, agenda de citas y reservas mediante QR. ¡Gracias por confiar en Fénix!";

        app(Notificador::class)->aUsuario($user->id, 'BIENVENIDA', "Bienvenido(a) {$user->name}", $mensaje);
    }

    /**
     * Avisa al Super Administrador (notificación interna + correo opcional) de un nuevo registro.
     */
    private function notificarNuevoRegistro(User $user, string $codigoActivacion): void
    {
        $superAdmin = User::where('es_super_admin', true)->first();
        if (! $superAdmin) {
            return;
        }

        $cuando = now()->format('d/m/Y H:i');
        $plan = $user->plan?->nombre ?? 'Sin plan';
        $mensaje = "Usuario: {$user->name}\nCorreo: {$user->email}\nFecha: {$cuando}\nPlan: {$plan}\n\nCódigo de activación: {$codigoActivacion}\n(la cuenta queda bloqueada hasta que se la entregues y la active en /activar)";

        $notificador = app(Notificador::class);
        $notificador->aUsuario($superAdmin->id, 'ADMIN', 'Nuevo usuario registrado — pendiente de activación', $mensaje);

        // Correo opcional al super-admin (en dev queda en el log si MAIL_MAILER=log).
        try {
            $notificador->correo(
                $superAdmin->email,
                'Nuevo usuario registrado — Fénix',
                'Nuevo usuario registrado (pendiente de activación)',
                ["Nombre: {$user->name}", "Correo: {$user->email}", "Fecha: {$cuando}", "Plan: {$plan}", "Código de activación: {$codigoActivacion}"],
            );
        } catch (\Throwable $e) {
            // No bloquear el registro si falla el envío de correo.
        }
    }

    /**
     * Inicio de sesión: devuelve un token de acceso (Sanctum).
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no son correctas.'],
            ]);
        }

        if ($user->estado === 'PENDIENTE_ACTIVACION') {
            throw ValidationException::withMessages([
                'email' => ['Tu cuenta está pendiente de activación. Solicita tu código de 6 dígitos al administrador de Fénix.'],
            ]);
        }

        if ($user->estado === 'SUSPENDIDO') {
            throw ValidationException::withMessages([
                'email' => ['Tu cuenta está suspendida. Contacta al administrador.'],
            ]);
        }

        if ($user->estado === 'DESACTIVADO' || ! $user->activo) {
            throw ValidationException::withMessages([
                'email' => ['Esta cuenta está desactivada.'],
            ]);
        }

        // Multiempresa: si la EMPRESA fue suspendida/desactivada por el super-admin,
        // ningún usuario de esa empresa puede entrar (salvo el super-admin).
        $empresa = $user->empresaDeCobro();
        if ($empresa && ! $user->esSuperAdmin()) {
            if ($empresa->estado === 'SUSPENDIDO') {
                throw ValidationException::withMessages([
                    'email' => ['La cuenta de tu empresa está suspendida. Contacta al administrador de Fénix.'],
                ]);
            }
            if ($empresa->estado === 'DESACTIVADO' || ! $empresa->activo) {
                throw ValidationException::withMessages([
                    'email' => ['La cuenta de tu empresa está desactivada.'],
                ]);
            }
        }

        $user->forceFill(['ultimo_acceso' => now()])->increment('veces_login');

        $token = $this->crearToken($user, $request);

        return response()->json([
            'user' => $user->load('rol', 'plan'),
            'token' => $token,
        ]);
    }

    /**
     * Datos del usuario autenticado.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('rol.permisos', 'plan', 'bodega', 'workspaceOwner');

        // Identidad del negocio (nombre, tipo, logo, estado operativo): SIEMPRE
        // la propia empresa, nunca la de otro negocio vinculado. El plan y la
        // membresía sí se comparten entre negocios vinculados ("Mis negocios"),
        // así que esos se leen de la empresa GOBERNANTE del grupo.
        $empresaPropia = $user->empresaDeCobro();
        $empresaGobernante = $empresaPropia?->empresaGobernante();
        $owner = $user->billingOwner();
        $creditos = app(\App\Services\CreditService::class)->saldos($user);

        $user->setAttribute('facturacion_saas', [
            'modo_cobro' => $empresaGobernante->modo_cobro ?? $owner->modo_cobro,
            'membresia_vence_at' => ($empresaGobernante->membresia_vence_at ?? $owner->membresia_vence_at)?->toIso8601String(),
            'membresia_vencida' => $user->membresiaVencida(),
            'creditos_facturacion' => (int) ($creditos['facturacion'] ?? 0),
        ]);

        if ($empresaPropia) {
            $user->setAttribute('empresa_info', [
                'id' => $empresaPropia->id,
                'nombre' => $empresaPropia->nombre,
                'tipo_negocio' => $empresaPropia->tipoNegocio?->only(['id', 'clave', 'nombre']),
                'plan' => $empresaGobernante?->plan?->only(['id', 'nombre']),
                'estado' => $empresaPropia->estado,
                'logo_url' => $empresaPropia->logo_url,
                'logo_emoji' => $empresaPropia->logo_emoji,
                'politicas' => $empresaPropia->politicas,
                'instagram_url' => $empresaPropia->instagram_url,
                'tiktok_url' => $empresaPropia->tiktok_url,
                'facebook_url' => $empresaPropia->facebook_url,
                'whatsapp_url' => $empresaPropia->whatsapp_url,
                'es_admin_empresa' => (bool) $user->es_admin_empresa,
            ]);
        }

        return response()->json($user);
    }

    /**
     * Emite el token de acceso. Los tokens web/PWA siguen sin expirar nunca
     * (como hasta ahora, no hay refresh-token para ellos); si el cliente
     * manda `plataforma: "movil"` (la app nativa), el token vence a los 60
     * días, para que una sesión perdida/robada del celular no quede válida
     * para siempre.
     */
    private function crearToken(User $user, Request $request): string
    {
        $expira = $request->input('plataforma') === 'movil' ? now()->addDays(60) : null;
        $nombre = $expira ? 'logix-movil' : 'logix';

        return $user->createToken($nombre, ['*'], $expira)->plainTextToken;
    }

    /**
     * Cierre de sesión: revoca el token actual.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    /**
     * Solicita un enlace de recuperación de contraseña (se envía por correo).
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink($request->only('email'));

        // Respuesta neutra: no revela si el correo existe o no.
        return response()->json([
            'message' => 'Si el correo está registrado, te enviamos un enlace para restablecer la contraseña.',
            'status' => $status,
        ]);
    }

    /**
     * Restablece la contraseña con el token recibido por correo.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
                // Revoca tokens de sesión activos por seguridad.
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'email' => ['El enlace de recuperación no es válido o ya expiró.'],
            ]);
        }

        return response()->json(['message' => 'Contraseña actualizada. Ya puedes iniciar sesión.']);
    }
}
