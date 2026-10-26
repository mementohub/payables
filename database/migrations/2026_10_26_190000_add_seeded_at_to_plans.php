<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Când s-a umplut ultima oară planul din raport.
 *
 * Planul pornește din P&L: cheltuielile, lună cu lună, vin din raportul
 * construit al anului de bază. Dacă raportul se reface — o corectură de
 * cont, o cheltuială mutată pe altă linie — planul rămâne pe cifrele
 * vechi până la următoarea împrospătare. Data asta spune dacă s-a întâmplat
 * între timp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->timestamp('seeded_at')->nullable()->after('start');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('seeded_at');
        });
    }
};
