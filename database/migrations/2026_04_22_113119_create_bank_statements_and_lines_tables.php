<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('data_extras');
            $table->string('banca')->nullable();
            $table->string('iban', 40);
            $table->string('operator')->nullable();
            $table->string('moneda', 5)->nullable();
            $table->integer('lines_count')->default(0);
            $table->integer('unallocated_count')->default(0);
            $table->decimal('total_incoming', 18, 4)->default(0);
            $table->decimal('total_outgoing', 18, 4)->default(0);
            $table->decimal('total_unallocated', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'data_extras', 'iban'], 'bank_statements_unique');
            $table->index(['company_id', 'data_extras']);
        });

        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained()->cascadeOnDelete();
            $table->date('data_doc');
            $table->string('tip_doc', 10);
            $table->string('nr_doc', 30);
            $table->string('direction', 10);
            $table->string('partener_name')->nullable();
            $table->foreignId('partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->string('moneda', 5)->nullable();
            $table->decimal('val_mon', 18, 4)->default(0);
            $table->decimal('val_allocated', 18, 4)->default(0);
            $table->json('allocated_invoice_ids')->nullable();
            $table->timestamps();

            $table->unique(
                ['bank_statement_id', 'data_doc', 'tip_doc', 'nr_doc'],
                'bank_statement_lines_unique'
            );
            $table->index(['bank_statement_id', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statements');
    }
};
