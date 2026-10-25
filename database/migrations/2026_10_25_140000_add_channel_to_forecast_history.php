<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pe ce canal s-a vândut: retail, B2B, site, call center, francize.
 *
 * Planul se face pe categorie de produs, fiindcă acolo stau pârghiile, dar
 * raportul se citește și pe canal — iar amestecul canalelor dintr-o
 * categorie se ia din anul de bază, nu se inventează.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forecast_history', function (Blueprint $table) {
            $table->string('channel', 40)->nullable()->after('key');
        });

        Schema::table('forecast_history', function (Blueprint $table) {
            $table->dropUnique('forecast_history_cell');
            $table->unique(['company_id', 'year', 'kind', 'key', 'channel', 'month'], 'forecast_history_cell');
        });
    }

    public function down(): void
    {
        Schema::table('forecast_history', function (Blueprint $table) {
            $table->dropUnique('forecast_history_cell');
            $table->dropColumn('channel');
            $table->unique(['company_id', 'year', 'kind', 'key', 'month'], 'forecast_history_cell');
        });
    }
};
