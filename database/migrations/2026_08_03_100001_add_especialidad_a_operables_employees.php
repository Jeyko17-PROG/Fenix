<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Especialidad del artista (ej. "Línea fina", "Neotradicional", "Realismo", "Blackwork"). */
    public function up(): void
    {
        Schema::table('operables_employees', function (Blueprint $table) {
            $table->string('especialidad', 100)->nullable()->after('tipo_operario');
        });
    }

    public function down(): void
    {
        Schema::table('operables_employees', function (Blueprint $table) {
            $table->dropColumn('especialidad');
        });
    }
};
