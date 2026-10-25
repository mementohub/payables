<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lunile pe care anul de bază nu le-a trăit încă, scrise de mână.
 *
 * Un plan făcut în octombrie pornește dintr-un an de bază cu nouă luni
 * adevărate și trei care n-au venit. Până acum ultimele trei se ghiceau
 * singure, din anul dinainte înmulțit cu cât a crescut anul — o estimare
 * cinstită, dar pe care omul n-o putea atinge. Aici își scrie el cifrele
 * pentru octombrie, noiembrie și decembrie: câți pasageri așteaptă, cu ce
 * bani, cu ce cheltuieli.
 *
 * Istoricul strâns din ERP-uri rămâne curat, în `forecast_history`; ce se
 * scrie aici e un strat peste el, care se poate șterge oricând ca să se
 * întoarcă la estimarea automată.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('base_estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            // category, charter, opex sau below — aceleași feluri ca pârghiile.
            $table->string('kind', 12);
            $table->string('key');
            $table->string('label')->nullable();
            $table->string('unit', 10)->default('pax');
            $table->unsignedTinyInteger('month');

            $table->decimal('volume', 14, 2)->nullable();
            $table->decimal('capacity', 14, 2)->nullable();
            $table->decimal('load', 6, 4)->nullable();
            $table->decimal('unit_net', 14, 2)->nullable();
            $table->decimal('unit_margin', 14, 2)->nullable();
            $table->decimal('unit_cost', 14, 2)->nullable();
            $table->decimal('amount', 16, 2)->nullable();

            $table->text('note')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'year', 'kind', 'key', 'month'], 'base_estimates_cell');
            $table->index(['company_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_estimates');
    }
};
