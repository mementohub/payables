<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A company whose own ERP credentials point at the same database as the
     * OMC connection keeps its books there: read them live through the OMC
     * connection instead of the direct copy. The earlier auto-link only
     * fired when a single company used eTrip Christian Tour.
     */
    public function up(): void
    {
        $database = (string) config('database.connections.omc.database', '');

        if ($database === '') {
            return;
        }

        DB::table('companies')
            ->whereNull('erp_connection')
            ->where('db_database', $database)
            ->update(['erp_connection' => 'omc']);
    }

    public function down(): void
    {
        // The link is configuration, not data: nothing to undo.
    }
};
