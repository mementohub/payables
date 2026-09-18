<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The responsabil / ordonator approvals (seeded demo data only) give way
     * to the department workflow: their tables and columns go, the demo
     * accounts go, and the people already using the app become admins, who
     * then hand out the roles and department memberships.
     */
    public function up(): void
    {
        Schema::dropIfExists('invoice_approvals');
        Schema::dropIfExists('partner_department');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_is_fully_approved_index');
            $table->dropColumn(['responsabili_approved_at', 'is_fully_approved', 'fully_approved_at']);
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropUnique('departments_name_type_unique');
            $table->dropIndex('departments_type_index');
            $table->dropColumn('type');
        });

        DB::table('users')->where('email', 'like', '%@example.test')->delete();
        DB::table('users')->where('email', 'not like', '%@example.test')->update(['roles' => json_encode(['admin'])]);
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('type', 20)->default('responsabil')->index();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('responsabili_approved_at')->nullable();
            $table->boolean('is_fully_approved')->default(false)->index();
            $table->timestamp('fully_approved_at')->nullable();
        });
    }
};
