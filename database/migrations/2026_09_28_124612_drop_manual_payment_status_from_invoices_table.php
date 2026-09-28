<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scoate marcajul manual al plății.
 *
 * Plata se face și se stinge în OMC, iar aplicația doar citește sumele
 * decontate. Cât timp exista și un marcaj pus cu mâna, despre aceeași factură
 * se puteau spune două lucruri diferite — iar cel scris aici îl acoperea pe
 * cel din ERP. Rămâne unul singur, cel din OMC; istoricul marcajelor de până
 * acum se păstrează în evenimentele facturii.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Indexul s-a născut pe `payment_status` și a rămas cu numele acela
        // după redenumirea coloanei; fără să-l scoatem întâi, coloana nu
        // poate pleca.
        $indexes = collect(Schema::getIndexes('invoices'))->pluck('name');

        Schema::table('invoices', function (Blueprint $table) use ($indexes) {
            if ($indexes->contains('invoices_payment_status_index')) {
                $table->dropIndex('invoices_payment_status_index');
            }

            $table->dropConstrainedForeignId('payment_status_updated_by_id');
            $table->dropColumn(['payment_status_manual', 'payment_status_updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('payment_status_manual', 20)->nullable()->after('val_mon_storno');
            $table->timestamp('payment_status_updated_at')->nullable()->after('payment_status_manual');
            $table->foreignId('payment_status_updated_by_id')->nullable()->after('payment_status_updated_at')->constrained('users')->nullOnDelete();
        });
    }
};
