<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What routing an invoice to a department reads from OMC: per line the
     * account and its analytic, the cost centre (loc), the booking or
     * rotation reference (com_int), the expense object and the actual
     * supplier behind an intra-group line; per invoice the office it was
     * booked at, when OMC last touched it, and whether OMC still holds it.
     */
    public function up(): void
    {
        Schema::table('invoice_details', function (Blueprint $table) {
            $table->string('account', 20)->nullable()->after('proc_tva');
            $table->string('analytic', 120)->nullable()->after('account');
            $table->string('loc', 80)->nullable()->after('analytic');
            $table->string('com_int', 40)->nullable()->after('loc');
            $table->string('nr_obiect', 40)->nullable()->after('com_int');
            $table->string('furnizor', 255)->nullable()->after('nr_obiect');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('office', 120)->nullable()->after('emitent');
            $table->timestamp('omc_modified_at')->nullable()->after('office');
            $table->timestamp('omc_removed_at')->nullable()->after('omc_modified_at');
            $table->index(['company_id', 'partener_type', 'data_doc'], 'invoices_company_type_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_company_type_date_index');
            $table->dropColumn(['office', 'omc_modified_at', 'omc_removed_at']);
        });

        Schema::table('invoice_details', function (Blueprint $table) {
            $table->dropColumn(['account', 'analytic', 'loc', 'com_int', 'nr_obiect', 'furnizor']);
        });
    }
};
