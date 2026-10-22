<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contul pe care l-am folosit ultima dată la plata unui furnizor.
 *
 * Când furnizorul are patru conturi în lei și niciunul implicit, aplicația
 * n-are cum să aleagă — dar omul a ales deja, luna trecută, și tot pe ăla
 * alege și acum. Se ține pe furnizor și pe monedă: un furnizor poate avea
 * un cont pentru lei și altul pentru euro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_account_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 5)->nullable();
            $table->string('iban', 40);
            $table->string('bic', 20)->nullable();
            $table->unsignedInteger('times_used')->default(1);
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('last_used_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['partner_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_account_choices');
    }
};
