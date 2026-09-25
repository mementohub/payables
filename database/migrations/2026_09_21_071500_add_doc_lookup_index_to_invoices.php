<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index pentru găsirea facturii-pereche din cealaltă companie.
 *
 * Lista de facturi primite caută, pentru fiecare rând, factura de client cu
 * același număr și aceeași dată din altă companie a grupului. Căutarea se face
 * pe (nr_doc, data_doc), fără companie, deci niciunul dintre indecșii vechi —
 * toți începeau cu `company_id` — nu o putea servi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['nr_doc', 'data_doc'], 'invoices_doc_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_doc_lookup_idx');
        });
    }
};
