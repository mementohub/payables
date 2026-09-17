<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The e-invoice matcher walks a company's partners by CUI to build its index.
 * Without this the database sorts hundreds of thousands of rows on every sync
 * slice; with it the scan follows the index and touches nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->index(['company_id', 'cui']);
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'cui']);
        });
    }
};
