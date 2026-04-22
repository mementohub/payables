<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_line_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->date('data_doc_com');
            $table->string('tip_doc_com', 10);
            $table->string('nr_doc_com', 30);
            $table->decimal('val_fin', 18, 4)->default(0);
            $table->decimal('val_com', 18, 4)->default(0);
            $table->timestamps();

            $table->index(['bank_statement_line_id'], 'bsla_line_idx');
            $table->index(['invoice_id'], 'bsla_invoice_idx');
        });

        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->dropColumn('allocated_invoice_ids');
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->json('allocated_invoice_ids')->nullable();
        });

        Schema::dropIfExists('bank_statement_line_allocations');
    }
};
