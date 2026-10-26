<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cheltuiala care urmează venitul, nu calendarul.
 *
 * Comisionul bancar nu crește pentru că trece un an, ci pentru că se vinde
 * mai mult: e un procent din încasările directe. La fel promovarea pe Google
 * și pe Facebook — se cumpără pentru vânzarea către om — iar promovarea
 * online ține de vânzarea de pe site. Liniile astea se plănuiesc ca procent
 * din venitul lor, iar procentul vine din istoric.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_lines', function (Blueprint $table) {
            // „percent” când linia urmează venitul; gol când merge pe trend.
            $table->string('driver', 16)->nullable()->after('kind');
            // Venitul după care se ia: „b2c”, „site”, „b2b”, „revenue”.
            $table->string('driver_base', 16)->nullable()->after('driver');
            $table->decimal('driver_rate', 12, 8)->nullable()->after('driver_base');
        });
    }

    public function down(): void
    {
        Schema::table('plan_lines', function (Blueprint $table) {
            $table->dropColumn(['driver', 'driver_base', 'driver_rate']);
        });
    }
};
