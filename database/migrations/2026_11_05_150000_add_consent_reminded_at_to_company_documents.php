<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Când a plecat aducerea aminte.
 *
 * Nu de dragul evidenței, ci ca să nu plece de două ori: programul rulează în
 * fiecare zi, iar o a doua aducere aminte pentru același termen ar fi, pentru
 * omul care și-a făcut treaba, doar o împunsătură în plus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_documents', function (Blueprint $table) {
            $table->timestamp('consent_reminded_at')->nullable()->after('consent_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('company_documents', function (Blueprint $table) {
            $table->dropColumn('consent_reminded_at');
        });
    }
};
