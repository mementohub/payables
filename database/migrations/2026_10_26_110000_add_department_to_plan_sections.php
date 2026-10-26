<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categoria adăugată cu mâna își alege stăpânul.
 *
 * Categoriile venite din istoric își știu departamentul din configurație.
 * Una scrisă în plan abia acum — o destinație nouă, un produs nou — n-are de
 * unde, așa că i se spune aici cine o ține. Fără rândul ăsta ar rămâne a
 * nimănui, adică numai a conducerii.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_sections', function (Blueprint $table) {
            $table->string('department')->nullable()->after('key');
        });
    }

    public function down(): void
    {
        Schema::table('plan_sections', function (Blueprint $table) {
            $table->dropColumn('department');
        });
    }
};
