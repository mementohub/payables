<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Istoricul din care se judecă planul, strâns o dată și ținut la îndemână.
 *
 * Cifrele pe cursă se adună din eTrip cu o interogare de două minute — prea
 * lentă pentru o pagină care se deschide des. Aici stau gata socotite, pe
 * ani, ca omul să vadă 2024, 2025 și 2026 unul lângă altul când hotărăște
 * gradul de ocupare și banii pe pasager pentru anul care vine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            // category (pe categorie de produs) sau charter (pe cursă).
            $table->string('kind', 12);
            $table->string('key');
            $table->string('unit', 10)->default('pax');
            $table->unsignedTinyInteger('month');

            $table->decimal('volume', 14, 2)->default(0);
            $table->decimal('capacity', 14, 2)->nullable();
            $table->decimal('sold', 14, 2)->nullable();
            $table->decimal('net', 16, 2)->default(0);
            $table->decimal('margin', 16, 2)->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'year', 'kind', 'key', 'month'], 'forecast_history_cell');
            $table->index(['company_id', 'kind', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_history');
    }
};
