<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_flow_overrides', function (Blueprint $table) {
            $table->id();
            // The report line: the OPEX category key for D lines (their codes
            // follow the catalogue order), the line code for everything else.
            $table->string('line', 40);
            $table->date('week');
            $table->decimal('amount', 18, 2);
            $table->string('note', 255)->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['line', 'week']);
            $table->index('week');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_flow_overrides');
    }
};
