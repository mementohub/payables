<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etrip_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 120);
            $table->string('vat_no', 40)->nullable();
            $table->string('company_no', 40)->nullable();
            $table->string('currency', 5)->nullable();
            $table->string('country', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
            $table->string('match_source', 10)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->unique('partner_id');
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etrip_suppliers');
    }
};
