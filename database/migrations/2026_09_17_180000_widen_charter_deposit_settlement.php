<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a deposit is regularised was kept in 60 characters, and the CTR 281
 * note of the contract pack is 67: MySQL refused the row and the whole pack
 * with it. The note gets the room the other contract terms have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charter_contracts', function (Blueprint $table) {
            $table->string('deposit_settlement', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('charter_contracts', function (Blueprint $table) {
            $table->string('deposit_settlement', 60)->nullable()->change();
        });
    }
};
