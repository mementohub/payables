<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aeroportul de plecare și destinația unei curse, scrise pe linie.
 *
 * Până acum se citeau tăind cheia în două la fiecare afișare. Cheia e însă
 * un nume, iar numele se mai schimbă: prima cursă botezată altfel ar fi
 * ieșit din toate totalurile. Așa stau scrise o dată, la semănat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_lines', function (Blueprint $table) {
            $table->string('hub', 8)->nullable()->after('key');
            $table->string('destination', 8)->nullable()->after('hub');
        });
    }

    public function down(): void
    {
        Schema::table('plan_lines', function (Blueprint $table) {
            $table->dropColumn(['hub', 'destination']);
        });
    }
};
