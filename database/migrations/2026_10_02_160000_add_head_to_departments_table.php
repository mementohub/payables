<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Șeful departamentului: cine e anunțat când cineva rutează facturi acolo.
 *
 * Nepus, vestea merge la toți oamenii departamentului — mai bine câțiva
 * anunțați decât o factură care stă fiindcă nu știe nimeni de ea.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('departments', 'head_user_id')) {
            return;
        }

        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('head_user_id')->nullable()->after('parent_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('head_user_id');
        });
    }
};
