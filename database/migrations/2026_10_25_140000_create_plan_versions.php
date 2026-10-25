<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Variantele planului, păstrate întregi.
 *
 * Un buget nu se face dintr-o dată: se încearcă o creștere de charter, se
 * vede ce iese, se dă înapoi, se încearcă altceva. Fără variante salvate,
 * fiecare încercare o șterge pe cea dinainte și nimeni nu mai poate spune ce
 * s-a propus luna trecută. Aici stă pârghie cu pârghie, cu cine a salvat-o,
 * când și de ce — ca să se poată încărca înapoi sau scoasă pe hârtie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->text('note')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            // Cum era citit planul când s-a salvat varianta: perioada, baza
            // chiriilor și recunoașterea venitului. Altfel cifrele din
            // rezumat n-ar spune nimic.
            $table->string('period', 8)->default('ytd12');
            $table->string('basis', 8)->default('ras');
            $table->string('mode', 12)->default('operational');

            // Pârghiile, așa cum erau, și cifrele care ieșeau din ele.
            $table->json('lines');
            $table->json('summary');
            $table->timestamps();

            $table->index(['plan_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_versions');
    }
};
