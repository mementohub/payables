<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Charter contracts settle their deposit at the last rotations of the
 * programme (CTR 317 art. 3.5, CTR 281). A deposit already paid outside
 * those terms is worth more than that to the forecast: it stops the next
 * payments until it runs out. The order is a term of the contract, so it
 * is kept next to the others.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charter_contracts', function (Blueprint $table) {
            $table->string('deposit_settlement_order', 10)
                ->default('last')
                ->after('deposit_settlement');
        });
    }

    public function down(): void
    {
        Schema::table('charter_contracts', function (Blueprint $table) {
            $table->dropColumn('deposit_settlement_order');
        });
    }
};
