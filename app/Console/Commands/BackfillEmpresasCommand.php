<?php

namespace App\Console\Commands;

use App\Support\BackfillEmpresas;
use Illuminate\Console\Command;

class BackfillEmpresasCommand extends Command
{
    protected $signature = 'logix:backfill-empresas';

    protected $description = 'Re-ejecuta el backfill multiempresa (idempotente): dueños→empresas, owner_id→empresa_id, módulos y billetera.';

    public function handle(): int
    {
        $resumen = BackfillEmpresas::run();

        $this->info('Backfill completado:');
        foreach ($resumen as $clave => $valor) {
            $this->line("  {$clave}: {$valor}");
        }

        return self::SUCCESS;
    }
}
