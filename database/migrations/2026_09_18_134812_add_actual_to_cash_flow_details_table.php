<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The current week is both the last actual column (up to yesterday)
     * and the first forecast one (the rest of it): its pieces are told
     * apart by this flag.
     */
    public function up(): void
    {
        Schema::table('cash_flow_details', function (Blueprint $table) {
            $table->boolean('actual')->default(false)->after('week');
            $table->index(['cash_flow_snapshot_id', 'line', 'actual', 'week'], 'cash_flow_details_cell_index');
            $table->dropIndex(['cash_flow_snapshot_id', 'line', 'week']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_flow_details', function (Blueprint $table) {
            $table->index(['cash_flow_snapshot_id', 'line', 'week']);
            $table->dropIndex('cash_flow_details_cell_index');
            $table->dropColumn('actual');
        });
    }
};
