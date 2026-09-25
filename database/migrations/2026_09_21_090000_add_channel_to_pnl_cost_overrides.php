<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O corectură poate muta cheltuiala pe altă linie, pe alt canal, sau pe
 * amândouă.
 *
 * Până acum se putea spune doar „cheltuiala asta e de alt fel”. Dar o
 * cheltuială poate fi pe linia potrivită și tot pe canalul greșit: o campanie
 * a site-ului ajunge pe B2B fiindcă n-are punct de lucru și se împarte pe
 * cheia de venit. Canalul ales de om bate cheia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pnl_cost_overrides', function (Blueprint $table) {
            $table->string('channel', 30)->nullable()->after('saf');
            $table->string('saf', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pnl_cost_overrides', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
