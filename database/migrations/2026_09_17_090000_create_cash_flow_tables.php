<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_flow_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->json('value');
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('charter_contracts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('season', 20);
            $table->string('status', 10);
            $table->string('operator', 80)->nullable();
            $table->string('currency', 5)->default('EUR');
            $table->unsignedSmallInteger('days_before_flight')->default(10);
            $table->decimal('deposit_percent', 5, 2)->nullable();
            $table->decimal('deposit_amount', 18, 2)->nullable();
            $table->date('deposit_due_date')->nullable();
            $table->boolean('deposit_paid')->default(false);
            $table->decimal('contract_value', 18, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['season', 'status']);
        });

        Schema::create('charter_flights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('charter_contract_id')->constrained()->cascadeOnDelete();
            $table->string('route', 60);
            $table->string('flight_no', 60)->nullable();
            $table->date('flight_date');
            $table->unsignedSmallInteger('seats')->nullable();
            $table->decimal('price_per_seat', 12, 4)->nullable();
            $table->decimal('net_value', 18, 2);
            $table->decimal('taxes', 18, 2)->default(0);
            $table->date('pay_date')->nullable();
            $table->date('taxes_pay_date')->nullable();
            $table->timestamps();

            $table->index(['charter_contract_id', 'flight_date']);
            $table->index('flight_date');
        });

        Schema::create('cash_flow_snapshots', function (Blueprint $table) {
            $table->id();
            $table->timestamp('built_at');
            $table->date('week_start');
            $table->string('status', 10);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->json('payload')->nullable();
            $table->json('sources')->nullable();
            $table->text('error')->nullable();
            $table->string('built_by', 100)->nullable();
            $table->timestamps();

            $table->index('built_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_flow_snapshots');
        Schema::dropIfExists('charter_flights');
        Schema::dropIfExists('charter_contracts');
        Schema::dropIfExists('cash_flow_settings');
    }
};
