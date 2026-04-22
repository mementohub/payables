<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('val_mon_paid', 18, 4)->default(0)->after('val_mon_tva');
            $table->date('data_inchidere')->nullable()->after('data_scadenta');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['val_mon_paid', 'data_inchidere']);
        });
    }
};
