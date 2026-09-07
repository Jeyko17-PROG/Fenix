<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CreditPackage;

class CreditPackagesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Paquetes de ejemplo para pruebas funcionales
        $packages = [
            ['name' => 'Micro paquete - 10 créditos', 'module' => 'facturacion', 'price_cop' => 5000, 'credits' => 10, 'active' => true],
            ['name' => 'Pequeño - 50 créditos', 'module' => 'facturacion', 'price_cop' => 20000, 'credits' => 50, 'active' => true],
            ['name' => 'Medio - 200 créditos', 'module' => 'facturacion', 'price_cop' => 70000, 'credits' => 200, 'active' => true],
            ['name' => 'Agenda - 50 créditos', 'module' => 'agenda', 'price_cop' => 15000, 'credits' => 50, 'active' => true],
            ['name' => 'Agenda - 200 créditos', 'module' => 'agenda', 'price_cop' => 55000, 'credits' => 200, 'active' => true],
        ];

        foreach ($packages as $p) {
            CreditPackage::updateOrCreate([
                'name' => $p['name'],
                'module' => $p['module'],
            ], $p);
        }
    }
}
