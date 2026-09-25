<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O corectură poate muta cheltuiala și pe altă categorie de produs.
 *
 * Pe axa de produs, cheltuiala se împarte pe ce a vândut canalul în luna aia.
 * Cheia e bună pentru cheltuielile comune, dar nu pentru cele care se văd cu
 * ochiul liber: o campanie pentru croaziere nu e a tuturor produselor. Ca și
 * la canal, ce alege omul bate cheia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pnl_cost_overrides', function (Blueprint $table) {
            $table->string('product', 60)->nullable()->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('pnl_cost_overrides', function (Blueprint $table) {
            $table->dropColumn('product');
        });
    }
};
