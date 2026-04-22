<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->string('emitent')->nullable()->after('partener_name');
            $table->string('cine_preda')->nullable()->after('emitent');
            $table->string('cine_primeste')->nullable()->after('cine_preda');
            $table->text('obs_txt')->nullable()->after('cine_primeste');
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->dropColumn(['emitent', 'cine_preda', 'cine_primeste', 'obs_txt']);
        });
    }
};
