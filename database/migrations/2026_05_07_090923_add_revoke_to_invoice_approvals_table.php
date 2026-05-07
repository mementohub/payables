<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_approvals', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('approved_at');
            $table->foreignId('revoked_by_id')->nullable()->after('revoked_at')
                ->constrained('users')->nullOnDelete();
            $table->text('revoke_reason')->nullable()->after('revoked_by_id');

            $table->dropUnique(['invoice_id', 'department_id']);
            $table->index(['invoice_id', 'department_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_approvals', function (Blueprint $table) {
            $table->dropIndex(['invoice_id', 'department_id', 'revoked_at']);
            $table->unique(['invoice_id', 'department_id']);

            $table->dropConstrainedForeignId('revoked_by_id');
            $table->dropColumn(['revoked_at', 'revoke_reason']);
        });
    }
};
