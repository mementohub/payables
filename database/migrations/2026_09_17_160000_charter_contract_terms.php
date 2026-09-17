<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Charter contracts carried one rule each: pay the rotation N days before the
 * flight and settle the airport taxes on the 5th of the following month. The
 * real contracts differ per counterparty — CTR 281 pays the taxes three days
 * after the flight, CTR 1585 collects them together with the rotation, the
 * airlines invoice Memento Air weekly — and one of them is money coming in
 * rather than going out. The terms of each contract are stored on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charter_contracts', function (Blueprint $table) {
            $table->string('counterparty', 120)->nullable()->after('name');
            $table->string('buyer', 120)->nullable()->after('counterparty');
            $table->string('direction', 3)->default('out')->after('buyer');
            $table->boolean('in_cash_flow')->default(true)->after('direction');
            $table->string('contract_no', 60)->nullable()->after('in_cash_flow');
            $table->date('signed_date')->nullable()->after('contract_no');
            $table->date('period_from')->nullable()->after('signed_date');
            $table->date('period_to')->nullable()->after('period_from');

            $table->string('payment_basis', 20)->default('flight')->after('days_before_flight');
            $table->string('taxes_rule', 24)->default('monthly_first_week')->after('payment_basis');
            $table->unsignedSmallInteger('taxes_days')->nullable()->after('taxes_rule');
            $table->unsignedTinyInteger('taxes_month_day')->default(5)->after('taxes_days');

            $table->string('deposit_settlement', 60)->nullable()->after('deposit_paid');
            $table->decimal('contract_value_with_taxes', 18, 2)->nullable()->after('contract_value');

            $table->string('invoicing', 255)->nullable()->after('contract_value_with_taxes');
            $table->string('fuel_rule', 400)->nullable()->after('invoicing');
            $table->decimal('fx_markup_pct', 5, 2)->default(0)->after('fuel_rule');
            $table->decimal('late_penalty_pct_per_day', 6, 3)->nullable()->after('fx_markup_pct');
            $table->text('cancellation_terms')->nullable()->after('late_penalty_pct_per_day');
            $table->string('source', 255)->nullable()->after('cancellation_terms');
            $table->string('confidence', 1)->nullable()->after('source');

            $table->index(['in_cash_flow', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::table('charter_contracts', function (Blueprint $table) {
            $table->dropIndex(['in_cash_flow', 'direction']);
            $table->dropColumn([
                'counterparty', 'buyer', 'direction', 'in_cash_flow', 'contract_no',
                'signed_date', 'period_from', 'period_to', 'payment_basis', 'taxes_rule',
                'taxes_days', 'taxes_month_day', 'deposit_settlement', 'contract_value_with_taxes',
                'invoicing', 'fuel_rule', 'fx_markup_pct', 'late_penalty_pct_per_day',
                'cancellation_terms', 'source', 'confidence',
            ]);
        });
    }
};
