<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('module')->index();
            $table->integer('change');
            $table->integer('balance_after');
            $table->string('type')->index(); // purchase, consumption, adjustment, refund
            $table->unsignedBigInteger('credit_package_id')->nullable();
            $table->unsignedBigInteger('payment_transaction_id')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
