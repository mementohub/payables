<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cât din categorie se vinde prin agenții partenere.
 *
 * Fiecare categorie de produs se vinde în două lumi: prin revânzători —
 * agențiile partenere — și direct, pe site, în agențiile noastre, prin
 * franciză sau la call center. Împărțirea nu e o faptă a istoriei, e o
 * hotărâre a anului care vine, așa că se scrie aici, pe fiecare lună, de
 * către omul care ține categoria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_lines', function (Blueprint $table) {
            $table->decimal('b2b', 6, 4)->nullable()->after('load');
        });
    }

    public function down(): void
    {
        Schema::table('plan_lines', function (Blueprint $table) {
            $table->dropColumn('b2b');
        });
    }
};
