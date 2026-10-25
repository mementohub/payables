<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nu tot ce se plănuiește se numără în pasageri.
 *
 * Turismul se măsoară pe pasager, dar corporate-ul din Tina — bilete de
 * avion, cazări, evenimente vândute firmelor — se măsoară pe serviciu. Un
 * bilet nu e un pasager și n-are ce căuta în aceeași sumă, deci fiecare rând
 * își spune unitatea.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_lines', function (Blueprint $table) {
            $table->string('unit', 10)->default('pax')->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('plan_lines', function (Blueprint $table) {
            $table->dropColumn('unit');
        });
    }
};
