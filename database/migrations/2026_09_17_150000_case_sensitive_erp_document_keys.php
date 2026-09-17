<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The ERP is PostgreSQL, where a document key is compared byte for byte, so
 * `GR/16/Inv1` and `GR/16/INV1` are two different invoices. The MySQL mirror
 * runs on utf8mb4_unicode_ci, which compares them as equal and also ignores
 * trailing spaces, so the second one hit the unique key and stopped the
 * history sync. The columns that carry an ERP document key are switched to a
 * binary collation, one statement per table so it is rebuilt once, and the
 * mirror can then hold exactly what the ERP holds.
 *
 * Other engines already compare these columns byte for byte.
 *
 * Rolling back widens the comparison again, so it fails if by then the tables
 * hold two keys that differ only in case or in trailing spaces.
 */
return new class extends Migration
{
    /** @var array<string, array<string, int>> */
    private const COLUMNS = [
        'invoices' => ['tip_doc' => 10, 'nr_doc' => 30],
        'invoice_payments' => ['tip_doc' => 10, 'nr_doc' => 30],
        'bank_statement_lines' => ['tip_doc' => 10, 'nr_doc' => 30],
    ];

    public function up(): void
    {
        $this->collate('utf8mb4_bin');
    }

    public function down(): void
    {
        $this->collate((string) (config('database.connections.mysql.collation') ?: 'utf8mb4_unicode_ci'));
    }

    private function collate(string $collation): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $changes = [];

            foreach ($columns as $column => $length) {
                if (Schema::hasColumn($table, $column)) {
                    $changes[] = sprintf(
                        'modify `%s` varchar(%d) character set utf8mb4 collate %s not null',
                        $column,
                        $length,
                        $collation,
                    );
                }
            }

            if ($changes !== []) {
                DB::statement(sprintf('alter table `%s` %s', $table, implode(', ', $changes)));
            }
        }
    }
};
