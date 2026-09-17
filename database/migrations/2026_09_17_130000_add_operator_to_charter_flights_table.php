<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charter_flights', function (Blueprint $table) {
            $table->string('operator', 80)->nullable()->after('charter_contract_id');
        });
    }

    public function down(): void
    {
        Schema::table('charter_flights', function (Blueprint $table) {
            $table->dropColumn('operator');
        });
    }
};
