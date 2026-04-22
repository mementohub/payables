<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->date('data_doc');
            $table->string('tip_doc', 10);
            $table->string('nr_doc', 30);
            $table->string('partener_type', 10)->nullable();
            $table->string('moneda', 5)->nullable();
            $table->decimal('curs', 18, 6)->nullable();
            $table->decimal('val_mon', 18, 4)->default(0);
            $table->decimal('val_mon_tva', 18, 4)->default(0);
            $table->date('data_scadenta')->nullable();
            $table->string('emitent')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'data_doc', 'tip_doc', 'nr_doc'], 'invoices_doc_unique');
            $table->index(['company_id', 'partener_type']);
            $table->index(['company_id', 'tip_doc']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
