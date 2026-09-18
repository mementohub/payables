<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Only the supplier side of OMC is mirrored from now on: the client
     * invoices with their lines and payments, the partners that are only
     * clients (mostly individual travellers) and the e-invoice XML (read from
     * OMC when a page opens it) go. Batched, so no statement holds the tables
     * for long.
     */
    public function up(): void
    {
        $batch = 20000;
        $max = (int) DB::table('invoices')->max('id');

        for ($from = 0; $from <= $max; $from += $batch) {
            $ids = DB::table('invoices')
                ->where('partener_type', 'client')
                ->whereBetween('id', [$from, $from + $batch - 1])
                ->pluck('id')
                ->all();

            foreach (array_chunk($ids, 2000) as $chunk) {
                DB::table('invoice_details')->whereIn('invoice_id', $chunk)->delete();
                DB::table('invoice_payments')->whereIn('invoice_id', $chunk)->delete();
                DB::table('invoices')->whereIn('id', $chunk)->delete();
            }
        }

        do {
            $ids = DB::table('partners')->where('is_client', true)->where('is_furnizor', false)->limit(5000)->pluck('id')->all();
            DB::table('partners')->whereIn('id', $ids)->delete();
        } while (count($ids) === 5000);

        DB::table('partners')->where('is_client', true)->update(['is_client' => false]);

        do {
            $ids = DB::table('e_invoices')->whereNotNull('msg_xml')->limit(2000)->pluck('id')->all();
            DB::table('e_invoices')->whereIn('id', $ids)->update(['msg_xml' => null]);
        } while (count($ids) === 2000);
    }

    /**
     * The rows are restored from the backup taken before, not re-created.
     */
    public function down(): void {}
};
