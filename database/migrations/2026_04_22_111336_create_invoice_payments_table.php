<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->date('data_doc');
            $table->string('tip_doc', 10);
            $table->string('nr_doc', 30);
            $table->date('data_repartizare')->nullable();
            $table->decimal('val_fin', 18, 4)->default(0);
            $table->decimal('val_com', 18, 4)->default(0);
            $table->string('moneda', 5)->nullable();
            $table->timestamps();

            $table->unique(
                ['invoice_id', 'data_doc', 'tip_doc', 'nr_doc', 'data_repartizare'],
                'invoice_payments_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
    }
};
