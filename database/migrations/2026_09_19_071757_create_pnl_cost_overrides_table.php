<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corecturile făcute de om peste maparea automată a cheltuielilor pe liniile
 * contului de profit și pierdere.
 *
 * Maparea din `PnlCostMap` e pe reguli (cont contabil, apoi text), deci
 * nimerește aproape tot, dar nu tot. Când cineva mută o cheltuială în altă
 * secțiune, mutarea se ține aici și bate regulile la următoarea construcție.
 *
 * Două feluri de corectură:
 *  - `line`  — tot ce cade azi pe linia X merge de acum pe linia Y;
 *  - `item`  — doar cheltuielile cu aceeași semnătură (cont | punct de lucru |
 *              partener) merg pe linia Y. Bate corectura de linie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pnl_cost_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 10);
            $table->string('match_key', 255);
            $table->string('saf', 20);
            $table->string('label', 255)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'scope', 'match_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pnl_cost_overrides');
    }
};
