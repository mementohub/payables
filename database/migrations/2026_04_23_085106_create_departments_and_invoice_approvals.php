<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20);
            $table->timestamps();

            $table->index('type');
            $table->unique(['name', 'type']);
        });

        Schema::create('department_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['department_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('partner_department', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['partner_id', 'department_id']);
            $table->index('department_id');
        });

        Schema::create('invoice_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->timestamp('approved_at');
            $table->timestamps();

            $table->unique(['invoice_id', 'department_id']);
            $table->index(['invoice_id', 'role']);
            $table->index('user_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('supervisors_approved_at')->nullable()->after('val_mon_paid');
            $table->boolean('is_fully_approved')->default(false)->index()->after('supervisors_approved_at');
            $table->timestamp('fully_approved_at')->nullable()->after('is_fully_approved');
        });

        Schema::dropIfExists('partner_user');
    }

    public function down(): void
    {
        Schema::create('partner_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['partner_id', 'user_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['supervisors_approved_at', 'is_fully_approved', 'fully_approved_at']);
        });

        Schema::dropIfExists('invoice_approvals');
        Schema::dropIfExists('partner_department');
        Schema::dropIfExists('department_user');
        Schema::dropIfExists('departments');
    }
};
