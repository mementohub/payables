<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The payment approval workflow.
     *
     * Each open supplier invoice is approved by every department that owns
     * part of it (the members of that department), then by Top Management,
     * who can also dispute it or postpone it to a date. Overhead invoices
     * get their final approval one by one; tourism and intra-group invoices
     * get it through the weekly payment run, which Top Management approves
     * as a whole against the cash position, and Treasury then sends to the
     * bank.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // admin | top_management | finance | treasury
            $table->json('roles')->nullable()->after('avatar');
        });

        Schema::table('invoices', function (Blueprint $table) {
            // run | invoice: where the final approval is given
            $table->string('approval_track', 8)->nullable()->after('assigned_at');
            // routing | department | final | approved | disputed | postponed
            $table->string('approval_status', 12)->nullable()->after('approval_track');
            $table->date('postponed_until')->nullable()->after('approval_status');
            $table->foreignId('final_decided_by_id')->nullable()->after('postponed_until')->constrained('users')->nullOnDelete();
            $table->timestamp('final_decided_at')->nullable()->after('final_decided_by_id');
            $table->text('final_comment')->nullable()->after('final_decided_at');
            $table->index(['company_id', 'approval_status']);
        });

        Schema::create('invoice_department_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 18, 2)->default(0);
            // pending | approved | disputed | postponed
            $table->string('status', 10)->default('pending');
            $table->date('postponed_until')->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['invoice_id', 'department_id']);
            $table->index(['department_id', 'status']);
        });

        Schema::create('payment_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 40)->unique();
            $table->date('pay_date');
            $table->date('due_until');
            // review | final | approved | exported | closed | cancelled
            $table->string('status', 10)->default('review');
            $table->text('note')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('exported_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('exported_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('payment_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('currency', 5)->nullable();
            // included | excluded
            $table->string('status', 10)->default('included');
            $table->text('comment')->nullable();
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['payment_run_id', 'invoice_id']);
            $table->index(['invoice_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_run_items');
        Schema::dropIfExists('payment_runs');
        Schema::dropIfExists('invoice_department_approvals');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'approval_status']);
            $table->dropConstrainedForeignId('final_decided_by_id');
            $table->dropColumn(['approval_track', 'approval_status', 'postponed_until', 'final_decided_at', 'final_comment']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('roles');
        });
    }
};
