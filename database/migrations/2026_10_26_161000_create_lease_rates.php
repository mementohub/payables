<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rata marginală de împrumut, luna cu luna.
 *
 * Standardul cere rata pe care am plăti-o ca să împrumutăm banii necesari,
 * pe o durată similară. Noi o luăm din buletinul BNR — rata medie la
 * creditele noi acordate societăților nefinanciare, în moneda contractului
 * și pe scadența lui — în luna în care începe contractul sau în care se
 * modifică. Odată luată, nu se mai schimbă: indexările nu ating rata.
 *
 * Lunile pentru care buletinul n-a apărut încă se trec provizoriu cu
 * ultima lună publicată și se înlocuiesc la publicare.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_rates', function (Blueprint $table) {
            $table->id();
            $table->string('currency', 3);
            // Luna buletinului, ca „2026-09”.
            $table->string('month', 7);
            // Scadența inițială: „<1y”, „1-5y”, „>5y”.
            $table->string('bucket', 8);
            $table->decimal('rate', 7, 4);
            $table->boolean('provisional')->default(false);
            $table->timestamps();

            $table->unique(['currency', 'month', 'bucket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_rates');
    }
};
