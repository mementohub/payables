<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cât dintr-o categorie zboară pe locuri de charter.
 *
 * Un sejur „Senior Voyage” care pleacă pe o cursă de-a noastră se vede în
 * două locuri: în categoria lui și în cursa pe care zboară. Planul le lua
 * pe amândouă, deci venitul lui intra de două ori — aproape o sută de
 * milioane pe an. Aici se ține partea care stă pe locuri de charter, ca
 * pornirea planului s-o poată scădea din categorie: cursa o duce oricum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forecast_history', function (Blueprint $table) {
            $table->decimal('charter_volume', 14, 2)->default(0)->after('volume');
            $table->decimal('charter_net', 16, 2)->default(0)->after('net');
            $table->decimal('charter_margin', 16, 2)->default(0)->after('margin');
        });
    }

    public function down(): void
    {
        Schema::table('forecast_history', function (Blueprint $table) {
            $table->dropColumn(['charter_volume', 'charter_net', 'charter_margin']);
        });
    }
};
