<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Costul zborului, ca să se poată socoti pragul și locul gol.
 *
 * La charter plătim avionul întreg, nu scaunele vândute: din costul locului
 * ies două cifre pe care omul le caută întâi — de la ce grad de ocupare
 * cursa începe să aducă bani (pragul) și cât ne costă locurile rămase
 * nevândute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forecast_history', function (Blueprint $table) {
            $table->decimal('cost', 16, 2)->default(0)->after('margin');
        });

        Schema::table('plan_lines', function (Blueprint $table) {
            $table->decimal('unit_cost', 14, 2)->nullable()->after('unit_margin');
        });
    }

    public function down(): void
    {
        Schema::table('forecast_history', fn (Blueprint $table) => $table->dropColumn('cost'));
        Schema::table('plan_lines', fn (Blueprint $table) => $table->dropColumn('unit_cost'));
    }
};
