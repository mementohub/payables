<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('payment_status', 16)->default('unpaid')->after('val_mon_paid');
            $table->timestamp('payment_status_updated_at')->nullable()->after('payment_status');
            $table->foreignId('payment_status_updated_by_id')->nullable()
                ->after('payment_status_updated_at')
                ->constrained('users')->nullOnDelete();

            $table->index('payment_status');
        });

        DB::statement(<<<'SQL'
            UPDATE invoices
            SET payment_status = CASE
                WHEN val_mon_paid <= 0.009 THEN 'unpaid'
                WHEN val_mon_paid + 0.01 >= val_mon THEN 'paid'
                ELSE 'partial'
            END
        SQL);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['payment_status']);
            $table->dropConstrainedForeignId('payment_status_updated_by_id');
            $table->dropColumn(['payment_status', 'payment_status_updated_at']);
        });
    }
};
