<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ce a costat fiecare întrebare pusă unui agent.
 *
 * Fără asta, cheltuiala se vede abia luna următoare, pe factura
 * furnizorului, la grămadă. Aici se vede pe loc și pe fiecare întrebare,
 * ca omul să știe cât dă înainte să dea prea mult.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            /** Contractul despre care s-a întrebat, dacă a fost unul. */
            $table->foreignId('contract_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('agent', 80);
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('tokens_cached')->default(0);
            /** În dolari, cum facturează furnizorul. Null = n-am știut prețul. */
            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->timestamps();

            $table->index(['agent', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
