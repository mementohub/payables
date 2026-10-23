<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registrul de contracte de închiriere, pentru IFRS 16.
 *
 * Până acum chiriile se retratau dintr-o estimare: toată chiria anului,
 * împărțită la o durată presupusă, la o dobândă presupusă. OMC ne dă ce s-a
 * plătit, lună de lună, pe fiecare proprietar și fiecare spațiu — dar nu și
 * până când ține contractul, dacă se prelungește și la ce rată ne-am
 * împrumuta noi pe termenul ăla. Alea se scriu aici, o dată, iar de atunci
 * calculul e cel din standard, nu o aproximare: datoria e valoarea actuală a
 * chiriilor rămase, dreptul de utilizare se amortizează liniar, iar dobânda
 * curge din datorie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();

            // Cum îl recunoaștem în registrul OMC: proprietarul și punctul de
            // lucru, exact cum le scrie acolo.
            $table->string('lessor');
            $table->string('location')->nullable();

            $table->string('currency', 5)->default('Lei');
            $table->decimal('monthly_payment', 14, 2);
            $table->boolean('in_advance')->default(true);
            $table->date('starts_on');
            $table->date('ends_on');
            // Rata de împrumut marginală, pe an.
            $table->decimal('discount_rate', 6, 4)->default(0.0800);
            // Indexarea anuală convenită, dacă există.
            $table->decimal('indexation', 5, 2)->nullable();

            // Contractele scurte sau de valoare mică rămân cheltuială, cum
            // permite standardul.
            $table->boolean('exempt')->default(false);
            $table->string('status', 12)->default('draft');
            $table->string('source', 10)->default('manual');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->unique(['company_id', 'lessor', 'location', 'starts_on'], 'leases_unique_per_place');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leases');
    }
};
