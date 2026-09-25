<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index funcțional pentru căutarea facturii de bază.
 *
 * Lista de facturi primite caută, pentru fiecare rând, factura pe care o
 * stornează sau o corectează, după `LOWER(TRIM(nr_doc))`. Funcția pe coloană
 * face orice index obișnuit inutil, așa că MySQL citea toată tabela pentru
 * fiecare pagină: ~2,9 secunde din cele 3,5 ale paginii.
 *
 * MySQL 8 poate indexa chiar expresia. Nu schimbăm potrivirea — `nr_doc_key`
 * ar fi fost la îndemână, dar el scoate și punctuația, deci ar lega între ele
 * facturi pe care codul de azi le ține separate.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Indecșii pe expresie sunt de MySQL 8; pe sqlite (testele) nu există,
        // iar acolo tabelele sunt oricum mici.
        if (! $this->onMysql()) {
            return;
        }

        DB::statement('alter table invoices add index invoices_company_doc_folded_idx (company_id, (lower(trim(nr_doc))))');
    }

    public function down(): void
    {
        if (! $this->onMysql()) {
            return;
        }

        DB::statement('alter table invoices drop index invoices_company_doc_folded_idx');
    }

    private function onMysql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }
};
