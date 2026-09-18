<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Routing supplier invoices to departments.
     *
     * Departments form one taxonomy in three groups: product categories
     * (they own tourism costs), sales channels and support departments.
     * Every invoice line gets a department, the sales channel of its booking
     * when it has one, and the rule that decided; a person can overrule it.
     * The editable rules map OMC's cost centres, partners, accounts and
     * offices to departments.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->unique()->after('id');
            $table->string('group', 12)->nullable()->after('type');
            $table->foreignId('parent_id')->nullable()->after('group')->constrained('departments')->nullOnDelete();
            $table->unsignedSmallInteger('sort')->default(0)->after('parent_id');
            $table->boolean('is_active')->default(true)->after('sort');
        });

        Schema::create('assignment_rules', function (Blueprint $table) {
            $table->id();
            // partner | loc | account | office
            $table->string('kind', 12);
            $table->string('pattern', 255);
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['kind', 'pattern']);
        });

        Schema::create('invoice_line_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('scv');
            $table->decimal('amount', 18, 2)->default(0);
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained('departments')->nullOnDelete();
            // What the rules other than the cost centre would have said: the
            // shadow-mode check of how well lines are routed before OMC tags them.
            $table->foreignId('predicted_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('rule', 24);
            $table->string('detail', 255)->nullable();
            $table->boolean('is_manual')->default(false);
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['invoice_id', 'scv']);
            $table->index(['department_id', 'invoice_id']);
            $table->index('rule');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('partner_id')->constrained()->nullOnDelete();
            // assigned | partial | unassigned
            $table->string('assignment_state', 12)->nullable()->after('department_id');
            $table->timestamp('assigned_at')->nullable()->after('assignment_state');
            $table->index(['company_id', 'assignment_state']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'assignment_state']);
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn(['assignment_state', 'assigned_at']);
        });

        Schema::dropIfExists('invoice_line_departments');
        Schema::dropIfExists('assignment_rules');

        Schema::table('departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'group', 'sort', 'is_active']);
        });
    }
};
