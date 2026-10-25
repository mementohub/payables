<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De unde pornește planul, când nu umblă nimeni la pârghii.
 *
 * Implicit, anul de bază dus mai departe cu trendul ultimilor trei ani.
 * Dar cine vrea un buget „ca anul ăsta, încă o dată” trebuie să-l poată
 * cere: altfel ar trebui să șteargă trendul cu mâna de pe fiecare rând.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('start', 10)->default('trend')->after('base_year');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('start');
        });
    }
};
