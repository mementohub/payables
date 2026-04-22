<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->string('bank')->nullable();
            $table->string('iban', 40);
            $table->string('currency', 5);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_discontinued')->default(false);
            $table->timestamps();

            $table->unique(['partner_id', 'iban']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_bank_accounts');
    }
};
