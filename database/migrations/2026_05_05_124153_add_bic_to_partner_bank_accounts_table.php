<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_bank_accounts', function (Blueprint $table) {
            $table->string('bic', 20)->nullable()->after('bank');
            $table->string('swift', 20)->nullable()->after('bic');
        });
    }

    public function down(): void
    {
        Schema::table('partner_bank_accounts', function (Blueprint $table) {
            $table->dropColumn(['bic', 'swift']);
        });
    }
};
