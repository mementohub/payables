<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The app reads the group's books through the external connections
     * only. Companies that still carry direct database credentials without
     * being linked to the OMC connection are the stale per-company copies:
     * they go, with everything synced from them. eTrip suppliers are then
     * keyed by the eTrip base they come from, not by a company.
     */
    public function up(): void
    {
        if (DB::table('companies')->whereNotNull('erp_connection')->exists()) {
            DB::table('companies')->whereNull('erp_connection')->whereNotNull('db_host')->delete();
        }

        Schema::table('etrip_suppliers', function (Blueprint $table) {
            $table->string('etrip_connection', 30)->nullable()->after('company_id');
        });

        foreach (DB::table('companies')->whereNotNull('etrip_connection')->get(['id', 'etrip_connection']) as $company) {
            DB::table('etrip_suppliers')->where('company_id', $company->id)->update(['etrip_connection' => $company->etrip_connection]);
        }

        DB::table('etrip_suppliers')->whereNull('etrip_connection')->delete();

        $duplicates = DB::table('etrip_suppliers')
            ->select('etrip_connection', 'code')
            ->groupBy('etrip_connection', 'code')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $ids = DB::table('etrip_suppliers')
                ->where('etrip_connection', $duplicate->etrip_connection)
                ->where('code', $duplicate->code)
                ->orderByRaw('case when partner_id is null then 1 else 0 end')
                ->orderBy('id')
                ->pluck('id');

            DB::table('etrip_suppliers')->whereIn('id', $ids->slice(1)->all())->delete();
        }

        Schema::table('etrip_suppliers', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'code']);
            $table->unique(['etrip_connection', 'code']);
            $table->index(['etrip_connection', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('etrip_suppliers', function (Blueprint $table) {
            $table->dropUnique(['etrip_connection', 'code']);
            $table->dropIndex(['etrip_connection', 'is_active']);
            $table->unique(['company_id', 'code']);
            $table->dropColumn('etrip_connection');
        });
    }
};
