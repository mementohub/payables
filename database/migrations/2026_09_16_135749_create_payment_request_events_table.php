<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['payment_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_request_events');
    }
};
