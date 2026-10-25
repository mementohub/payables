<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rândurile scoase din plan cu mâna.
 *
 * O cursă din istoric nu se taie ștergându-i liniile: pornirea din istoric
 * ar aduce-o înapoi la prima împrospătare. Aici se ține minte că omul n-o
 * vrea în planul ăsta — o cursă pe care n-o mai zburăm anul viitor, o
 * categorie pe care o închidem — și tot de aici se poate pune înapoi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->json('removed')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('removed');
        });
    }
};
