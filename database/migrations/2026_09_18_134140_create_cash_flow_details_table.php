<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What every cell of a cash-flow snapshot is made of: the booking
     * tranches, supplier services, charter rotations, invoices and payments
     * the build laid on each line and week, so a cell can be opened.
     * Written while the build runs (under its token), tied to the snapshot
     * when it is saved.
     */
    public function up(): void
    {
        Schema::create('cash_flow_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_flow_snapshot_id')->nullable()->constrained()->cascadeOnDelete();
            $table->uuid('build_token')->nullable()->index();
            $table->string('source', 24);
            $table->string('line', 16);
            $table->date('week');
            $table->string('kind', 24);
            $table->string('group', 60)->nullable();
            $table->string('label', 191);
            $table->string('reference', 191)->nullable();
            $table->date('date')->nullable();
            $table->string('currency', 5)->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->decimal('lei', 18, 2);
            $table->json('meta')->nullable();

            $table->index(['cash_flow_snapshot_id', 'line', 'week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_flow_details');
    }
};
