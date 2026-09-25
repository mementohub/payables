<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indecși pentru paginile care nu filtrează pe companie.
 *
 * Tabela `invoices` avea cincisprezece indecși, dar toți începeau cu
 * `company_id`. Facturi primite, coada de aprobări și căutarea de furnizori
 * întreabă fără companie, așa că MySQL nu putea folosi niciunul: citea toate
 * cele ~437.000 de rânduri și le și sorta pe disc. De aici cele 11 secunde pe
 * „Facturi primite” și cele ~2,5 secunde pentru fiecare număr de pe taburile
 * de aprobări.
 *
 *  - `primite_idx`  — lista și numărătoarea ei, plus ordinea după dată;
 *  - `approval_idx` — numerele de pe taburile din aprobări;
 *  - `partner_date_idx` — „ultima factură a furnizorului”, o subinterogare
 *    corelată care se executa pentru fiecare partener din listă.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['partener_type', 'omc_removed_at', 'data_doc'], 'invoices_primite_idx');
            $table->index(['partener_type', 'approval_status', 'approval_track', 'omc_removed_at'], 'invoices_approval_idx');
            $table->index(['partner_id', 'data_doc'], 'invoices_partner_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_primite_idx');
            $table->dropIndex('invoices_approval_idx');
            $table->dropIndex('invoices_partner_date_idx');
        });
    }
};
