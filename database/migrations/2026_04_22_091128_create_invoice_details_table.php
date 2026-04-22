<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->integer('scv');
            $table->string('articol');
            $table->string('detaliu_articol')->nullable();
            $table->decimal('cant', 18, 4)->default(0);
            $table->string('um', 10)->nullable();
            $table->decimal('pret', 18, 4)->default(0);
            $table->decimal('proc_tva', 8, 4)->nullable();
            $table->timestamps();

            $table->unique(['invoice_id', 'scv']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_details');
    }
};
