<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PlanSeeder::class,
            ModuloSeeder::class,
            TipoNegocioSeeder::class,
            AdminUserSeeder::class,
            CreditPackagesSeeder::class,
        ]);
    }
}
