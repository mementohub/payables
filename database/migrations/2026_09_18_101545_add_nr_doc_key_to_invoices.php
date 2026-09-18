<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The invoice number as e-invoices are matched on it (letters and digits
     * only, upper case), stored and indexed per supplier so matching is a
     * lookup rather than a scan of every invoice the supplier ever sent.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('nr_doc_key', 60)->nullable()->after('nr_doc');
            $table->index(['partner_id', 'nr_doc_key'], 'invoices_partner_nr_doc_key_index');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_partner_nr_doc_key_index');
            $table->dropColumn('nr_doc_key');
        });
    }
};
