<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unii furnizori nu completează câmpul de plată din XML, dar își scriu
 * contul în text, la forma juridică: „Cont IBAN RO77… Capital social…”.
 * Îl luăm și pe ăla, dar marcat: se arată în listă, nu se alege singur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_einvoice_accounts', function (Blueprint $table) {
            $table->boolean('from_text')->default(false)->after('bic');
        });
    }

    public function down(): void
    {
        Schema::table('partner_einvoice_accounts', function (Blueprint $table) {
            $table->dropColumn('from_text');
        });
    }
};
