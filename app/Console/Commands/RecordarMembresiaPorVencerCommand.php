<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Services\Notificador;
use Illuminate\Console\Command;

/**
 * Recordatorio al dueño de la empresa cuando HOY es el último día de su
 * membresía/prueba (membresia_vence_at cae en la fecha de hoy, hora
 * Colombia). Se programa 2 veces al día (8:00 a.m. y 4:00 p.m.) en
 * routes/console.php — cada corrida es independiente, así que el cliente
 * recibe el aviso dos veces ese día, tal como se pidió.
 */
class RecordarMembresiaPorVencerCommand extends Command
{
    protected $signature = 'membresia:recordar-vencimiento';

    protected $description = 'Avisa por correo y notificación interna a las empresas cuya membresía/prueba vence hoy.';

    public function handle(Notificador $notificador): int
    {
        $hoyBogota = now('America/Bogota')->toDateString();

        $empresas = Empresa::whereIn('modo_cobro', ['membresia', 'prueba'])
            ->where('estado', '!=', 'DESACTIVADO')
            ->whereNotNull('membresia_vence_at')
            ->whereDate('membresia_vence_at', $hoyBogota)
            ->with('owner')
            ->get();

        $enviados = 0;

        foreach ($empresas as $empresa) {
            // Solo la empresa GOBERNANTE del grupo: si hay negocios vinculados
            // ("Mis negocios") comparten la misma membresía, y no queremos
            // mandarle el mismo aviso una vez por cada negocio.
            if ($empresa->empresaGobernante()->id !== $empresa->id) {
                continue;
            }

            $owner = $empresa->owner;
            if (! $owner || $owner->esSuperAdmin()) {
                continue;
            }

            $titulo = 'Tu membresía vence hoy';
            $mensaje = "Recuerda generar el pago de tu membresía: hoy es el último día para utilizar Fénix. Renueva desde \"Planes\" para no perder el acceso.";

            $notificador->aUsuario($owner->id, 'MEMBRESIA', $titulo, $mensaje);

            if ($owner->email) {
                $notificador->correo(
                    $owner->email,
                    "{$titulo} — Fénix",
                    $titulo,
                    [
                        "Hola {$owner->name},",
                        'Recuerda generar el pago de tu membresía: hoy es el último día para utilizar Fénix.',
                        'Si no renuevas hoy, mañana el sistema quedará bloqueado hasta que hagas el pago.',
                    ],
                );
            }

            $enviados++;
        }

        $this->info("Recordatorios de membresía enviados: {$enviados}");

        return self::SUCCESS;
    }
}
