<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registrul de închirieri, pe linii, ca în calculul IFRS 16.
 *
 * Un contract nu e o singură linie: are trepte de preț, luni gratuite, acte
 * adiționale care prelungesc, remăsurări care îl înlocuiesc și încetări. Așa
 * îl ține și fișierul de calcul al societății — linie cu linie, cu
 * evenimentul anului și cu linia care o urmează — iar ce s-a auditat la
 * sfârșitul anului trecut (dreptul de utilizare și datoria rămasă) se
 * păstrează ca sold de pornire, nu se reface din nou.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            // Numărul liniei din calculul societății: după el se leagă
            // remăsurările de liniile pe care le înlocuiesc.
            $table->unsignedInteger('line_no')->nullable()->after('id');
            $table->unsignedInteger('group_no')->nullable()->after('line_no');
            $table->unsignedInteger('successor_no')->nullable()->after('group_no');
            // „remeasure” (se mută pe linia următoare, fără efect în P&L),
            // „derecognise” (încetare, cu câștig sau pierdere), „cancelled”.
            $table->string('event', 20)->nullable()->after('successor_no');
            $table->date('event_on')->nullable()->after('event');
            // Contract nou, prelungire sau remăsurare — cum se citește în
            // mișcarea anului.
            $table->string('category', 20)->nullable()->after('event_on');
            $table->unsignedInteger('term_months')->nullable()->after('ends_on');
            // Soldurile auditate de la care pornește anul.
            $table->date('opening_on')->nullable();
            $table->decimal('opening_rou', 18, 6)->nullable();
            $table->decimal('opening_liability', 18, 6)->nullable();
            $table->decimal('monthly_depreciation', 18, 6)->nullable();
            // Valoarea actuală recunoscută la începerea liniei.
            $table->decimal('present_value', 18, 6)->nullable();
            // De unde vine rata: „BNR EUR 1-5y 9_2026”, „linia 180”, etc.
            $table->string('rate_source')->nullable();

            $table->index(['company_id', 'line_no']);
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'line_no']);
            $table->dropColumn([
                'line_no', 'group_no', 'successor_no', 'event', 'event_on', 'category',
                'term_months', 'opening_on', 'opening_rou', 'opening_liability',
                'monthly_depreciation', 'present_value', 'rate_source',
            ]);
        });
    }
};
