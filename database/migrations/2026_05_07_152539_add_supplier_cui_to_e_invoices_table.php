<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('e_invoices', function (Blueprint $table) {
            $table->string('supplier_cui', 30)->nullable()->after('msg_cif');
            $table->index(['company_id', 'supplier_cui'], 'e_invoices_company_supplier_cui_idx');
        });
    }

    public function down(): void
    {
        Schema::table('e_invoices', function (Blueprint $table) {
            $table->dropIndex('e_invoices_company_supplier_cui_idx');
            $table->dropColumn('supplier_cui');
        });
    }
};
