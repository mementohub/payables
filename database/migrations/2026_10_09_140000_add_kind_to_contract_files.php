<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ce fel de document e fișierul: contractul însuși, un act adițional sau o
 * anexă.
 *
 * Până acum, orice fișier adăugat trecea drept versiune nouă a contractului.
 * Dar un act adițional nu înlocuiește contractul, ci îl schimbă: amândouă sunt
 * în vigoare și amândouă trebuie citite când cineva întreabă ce scrie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_files', function (Blueprint $table) {
            $table->string('kind', 20)->default('contract')->after('version');
            $table->date('signed_at')->nullable()->after('kind');
            $table->index(['contract_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('contract_files', function (Blueprint $table) {
            $table->dropIndex(['contract_id', 'kind']);
            $table->dropColumn(['kind', 'signed_at']);
        });
    }
};
