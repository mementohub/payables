<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chiria magazinului, pe lună.
 *
 * Costul unei agenții nu e numai din oameni și bonus: plătim și spațiul în
 * care stă. Chiria se bugetează acolo unde o știe cineva — la rețea, magazin
 * cu magazin — iar partea ei se scoate din linia de chirii a raportului, ca
 * să nu intre de două ori în plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_lines', function (Blueprint $table) {
            $table->decimal('rent', 14, 2)->nullable()->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('plan_lines', function (Blueprint $table) {
            $table->dropColumn('rent');
        });
    }
};
