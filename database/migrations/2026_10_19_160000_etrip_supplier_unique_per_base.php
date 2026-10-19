<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un partener poate avea un furnizor în fiecare bază eTrip, nu unul singur.
 *
 * Unicitatea era pusă numai pe partener, așa că ACCOR Hotels — care există
 * și la Christian Tour, și la Vacanza — se putea lega o singură dată. Cel
 * de-al doilea izbea constrângerea, iar `etrip:sync-suppliers` murea în
 * fiecare noapte la 03:30; Vacanza nu s-a sincronizat niciodată.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Cheia străină se sprijinea pe indexul unic; i se pune unul simplu
        // înainte, altfel baza nu lasă unicitatea veche să plece.
        Schema::table('etrip_suppliers', function (Blueprint $table) {
            $table->index('partner_id', 'etrip_suppliers_partner_id_index');
        });

        Schema::table('etrip_suppliers', function (Blueprint $table) {
            $table->dropUnique('etrip_suppliers_partner_id_unique');
            $table->unique(['etrip_connection', 'partner_id'], 'etrip_suppliers_base_partner_unique');
        });
    }

    public function down(): void
    {
        Schema::table('etrip_suppliers', function (Blueprint $table) {
            $table->dropUnique('etrip_suppliers_base_partner_unique');
            $table->unique('partner_id', 'etrip_suppliers_partner_id_unique');
            $table->dropIndex('etrip_suppliers_partner_id_index');
        });
    }
};
