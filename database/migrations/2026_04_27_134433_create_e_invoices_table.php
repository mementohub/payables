<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->string('msg_id', 40);
            $table->string('msg_cif', 20)->nullable();
            $table->string('msg_index_incarcare', 40)->nullable();
            $table->timestamp('msg_data_creare_d')->nullable();

            $table->date('data_doc_xml')->nullable();
            $table->string('tip_doc_xml', 30)->nullable();
            $table->string('nr_doc_xml', 60)->nullable();
            $table->string('partener_xml')->nullable();
            $table->string('cod_cci_xml', 30)->nullable();

            $table->decimal('total_amount', 15, 2)->nullable();
            $table->decimal('total_vat', 15, 2)->nullable();

            $table->text('msg_detalii')->nullable();
            $table->longText('msg_xml')->nullable();
            $table->timestamp('data_ins_omc')->nullable();
            $table->text('err_ins_omc')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'msg_id'], 'e_invoices_msg_unique');
            $table->index(['company_id', 'data_ins_omc'], 'e_invoices_company_data_ins_idx');
            $table->index(['company_id', 'invoice_id'], 'e_invoices_company_invoice_idx');
            $table->index(['company_id', 'nr_doc_xml'], 'e_invoices_company_nr_doc_idx');
            $table->index(['company_id', 'msg_data_creare_d'], 'e_invoices_company_msg_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e_invoices');
    }
};
