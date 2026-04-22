<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('cui')->nullable();
            $table->string('reg_com')->nullable();
            $table->boolean('is_furnizor')->default(false);
            $table->boolean('is_client')->default(false);
            $table->boolean('is_vat_payer')->nullable();
            $table->string('country', 3)->nullable();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 100)->nullable();
            $table->string('email')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'is_furnizor']);
            $table->index(['company_id', 'is_client']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};
