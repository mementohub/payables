<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('bank')->nullable();
            $table->string('iban', 40);
            $table->string('currency', 5);
            $table->string('bic', 20)->nullable();
            $table->string('swift', 20)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_discontinued')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'iban']);
            $table->index(['company_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_bank_accounts');
    }
};
