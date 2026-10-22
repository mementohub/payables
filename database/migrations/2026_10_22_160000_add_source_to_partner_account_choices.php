<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De unde știm alegerea: din fișierul de plată făcut în aplicație, sau din
 * extrasul bancar — adică din plata care chiar s-a dus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_account_choices', function (Blueprint $table) {
            $table->string('source', 10)->default('app')->after('bic');
        });
    }

    public function down(): void
    {
        Schema::table('partner_account_choices', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
