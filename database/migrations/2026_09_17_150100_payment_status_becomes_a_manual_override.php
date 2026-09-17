<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `payment_status` was written once, when it was added, from the amounts the
 * ERP had settled, and after that only by hand from the invoice page. The
 * sync keeps `val_mon_paid` and `val_mon_storno` current but never touched
 * it, so every invoice mirrored afterwards stayed on its `unpaid` default
 * however much the ERP had collected.
 *
 * The column becomes what it always was in practice: an override a member of
 * the payments department sets while the ERP does not know about a payment
 * yet. The status itself is derived from the settled amounts. Rows nobody
 * ever marked, recognised by an empty `payment_status_updated_at`, lose the
 * value the original backfill left behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('payment_status', 16)->nullable()->default(null)->change();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->renameColumn('payment_status', 'payment_status_manual');
        });

        DB::table('invoices')
            ->whereNull('payment_status_updated_at')
            ->update(['payment_status_manual' => null]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->renameColumn('payment_status_manual', 'payment_status');
        });

        DB::statement(<<<'SQL'
            update invoices
            set payment_status = case
                when val_mon_paid + val_mon_storno + 0.01 >= abs(val_mon) then 'paid'
                when payment_status is not null then payment_status
                when val_mon_paid + val_mon_storno <= 0.009 then 'unpaid'
                else 'partial'
            end
        SQL);

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('payment_status', 16)->nullable(false)->default('unpaid')->change();
        });
    }
};
