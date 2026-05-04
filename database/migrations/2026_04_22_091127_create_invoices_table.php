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
            $table->string('com_int', 40)->nullable();
            $table->date('data_calatoriei')->nullable();

            $table->date('data_doc_baza')->nullable();
            $table->string('tip_doc_baza')->nullable();
            $table->string('nr_doc_baza')->nullable();
            $table->foreignId('source_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('source_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'data_doc', 'tip_doc', 'nr_doc'], 'invoices_doc_unique');
            $table->index(['company_id', 'partener_type']);
            $table->index(['company_id', 'tip_doc']);
            $table->index(['company_id', 'tip_doc_baza', 'nr_doc_baza'], 'invoices_baza_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
