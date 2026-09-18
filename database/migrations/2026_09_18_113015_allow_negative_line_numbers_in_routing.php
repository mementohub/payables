<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * OMC numbers some invoice lines below zero (storno lines), so the line
     * position the routing is keyed on is signed, as it is on the lines.
     */
    public function up(): void
    {
        Schema::table('invoice_line_departments', function (Blueprint $table) {
            $table->integer('scv')->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_line_departments', function (Blueprint $table) {
            $table->unsignedInteger('scv')->change();
        });
    }
};
