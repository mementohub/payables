<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cine stă, de fapt, în spatele unei facturi refacturate.
 *
 * Factura vine de la Memento Air, dar zborul l-a făcut Anima Wings. Aici se
 * ține ce s-a găsit în baza casei care a refacturat: furnizorul adevărat,
 * documentul lui și cât din factura noastră îi revine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();

            $table->string('supplier_name', 200);
            $table->string('supplier_tax_id', 40)->nullable();

            /** Pe ce s-a făcut legătura: order | booking | article. */
            $table->string('basis', 12);
            /** Comanda, rezervarea sau articolul pe care s-a potrivit. */
            $table->string('reference', 200)->nullable();
            /** Documentul furnizorului adevărat, când se știe exact care e. */
            $table->string('doc_ref', 160)->nullable();
            $table->date('doc_date')->nullable();

            /**
             * Cât din factura noastră îi revine: partea (0..1) și suma în lei.
             * La Memento Air partea e o repartizare pe luna de charter, nu o
             * egalitate — de aceea `exact` rămâne fals acolo.
             */
            $table->decimal('share', 9, 6)->default(0);
            $table->decimal('val_lei', 16, 2)->default(0);
            $table->boolean('exact')->default(false);

            $table->timestamps();

            $table->index(['invoice_id', 'val_lei']);
            $table->index('supplier_name');
            $table->index(['supplier_tax_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_sources');
    }
};
