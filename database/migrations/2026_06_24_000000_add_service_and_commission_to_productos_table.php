<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddServiceAndCommissionToProductosTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->boolean('is_service')->default(false)->after('descripcion');
            $table->boolean('has_commission')->default(false)->after('is_service');
            $table->enum('commission_type', ['percentage', 'fixed'])->nullable()->after('has_commission');
            $table->decimal('commission_value', 10, 2)->nullable()->after('commission_type');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('productos', function (Blueprint $table) {
            if (Schema::hasColumn('productos', 'commission_value')) {
                $table->dropColumn(['commission_value', 'commission_type', 'has_commission', 'is_service']);
            }
        });
    }
}
