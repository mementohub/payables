<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aprobarea poate fi și pe o parte din factură, nu doar pe toată.
 *
 * `approved_amount` e suma aprobată la plată, în moneda facturii și cu TVA cu
 * tot — adică exact cât se duce în rulajul de plată. Gol înseamnă „tot”:
 * partea întreagă a departamentului, respectiv toată factura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_department_approvals', function (Blueprint $table) {
            $table->decimal('approved_amount', 18, 4)->nullable()->after('amount');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('approved_amount', 18, 4)->nullable()->after('final_comment');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_department_approvals', function (Blueprint $table) {
            $table->dropColumn('approved_amount');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('approved_amount');
        });
    }
};
