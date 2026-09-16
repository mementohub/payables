<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('etrip_supplier_id')->nullable()->constrained('etrip_suppliers')->nullOnDelete();
            $table->string('kind', 10);
            $table->string('supplier_name', 150);
            $table->string('reference', 100)->nullable();
            $table->decimal('requested_amount', 18, 2);
            $table->string('requested_currency', 5);
            $table->date('checkin_from')->nullable();
            $table->date('checkin_to')->nullable();
            $table->string('category', 10)->nullable();
            $table->decimal('expected_amount', 18, 2)->nullable();
            $table->string('expected_currency', 5)->nullable();
            $table->decimal('difference', 18, 2)->nullable();
            $table->decimal('difference_pct', 8, 2)->nullable();
            $table->string('level', 5)->nullable();
            $table->string('verdict', 20)->nullable();
            $table->json('snapshot')->nullable();
            $table->string('status', 12);
            $table->text('note')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('status_updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_updated_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'created_at']);
            $table->index('partner_id');
            $table->index('etrip_supplier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_requests');
    }
};
