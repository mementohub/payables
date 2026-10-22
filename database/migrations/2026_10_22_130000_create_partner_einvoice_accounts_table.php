<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conturile furnizorilor citite din e-Factură.
 *
 * OMC n-are IBAN pentru toți — din furnizorii cu facturi în ultimul an,
 * vreo cinci sute. Dar furnizorul își scrie contul chiar în XML-ul pe care
 * ni-l trimite, în câmpul lui de plată. Îl ținem aici, separat de ce vine
 * din OMC, ca să se vadă de unde a fost luat și să nu se amestece cu
 * evidența oficială; `partner_bank_accounts` rămâne oglinda ERP-ului.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_einvoice_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->string('iban', 40);
            $table->string('currency', 5)->nullable();
            $table->string('bank')->nullable();
            $table->string('bic', 20)->nullable();
            $table->unsignedBigInteger('e_invoice_id')->nullable();
            $table->string('nr_doc', 60)->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->unique(['partner_id', 'iban']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_einvoice_accounts');
    }
};
