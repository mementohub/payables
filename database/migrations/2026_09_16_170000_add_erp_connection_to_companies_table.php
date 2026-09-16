<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('erp_connection', 30)->nullable()->after('etrip_connection');
        });

        // The company verified against eTrip Christian Tour keeps its books in
        // OMC Christian Tour: link it when that is unambiguous.
        $candidates = DB::table('companies')->where('etrip_connection', 'etrip_chr')->pluck('id');

        if ($candidates->count() === 1) {
            DB::table('companies')->where('id', $candidates->first())->update(['erp_connection' => 'omc']);
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('erp_connection');
        });
    }
};
