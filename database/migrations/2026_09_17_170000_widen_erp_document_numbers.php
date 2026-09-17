<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A document number was mirrored into 30 characters. MySQL outside strict
 * mode cuts anything longer to fit, so two ERP documents whose numbers only
 * differ past that point arrive as one key, the second one overwrites the
 * first and their lines collide on (invoice_id, scv). The columns are widened
 * to 60, keeping the binary collation that already makes the comparison as
 * strict as the ERP's own.
 *
 * Numbers cut by an earlier sync stay cut; the next pass over those days
 * brings them back in full, as new rows.
 */
return new class extends Migration
{
    /** @var array<string, array<string, int>> */
    private const COLUMNS = [
        'invoices' => ['tip_doc' => 20, 'nr_doc' => 60],
        'invoice_payments' => ['tip_doc' => 20, 'nr_doc' => 60],
        'bank_statement_lines' => ['tip_doc' => 20, 'nr_doc' => 60],
    ];

    private const PREVIOUS = ['tip_doc' => 10, 'nr_doc' => 30];

    public function up(): void
    {
        $this->resize(self::COLUMNS);
    }

    public function down(): void
    {
        $this->resize(array_map(fn () => self::PREVIOUS, self::COLUMNS));
    }

    /**
     * @param  array<string, array<string, int>>  $columns
     */
    private function resize(array $columns): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $collation = (string) (config('database.connections.mysql.collation') ?: 'utf8mb4_unicode_ci');

        foreach ($columns as $table => $definition) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $changes = [];

            foreach ($definition as $column => $length) {
                if (Schema::hasColumn($table, $column)) {
                    $changes[] = sprintf(
                        'modify `%s` varchar(%d) character set utf8mb4 collate %s not null',
                        $column,
                        $length,
                        // Kept in step with the migration that made these keys
                        // case sensitive, so a rollback of one does not undo it.
                        $length >= self::PREVIOUS[$column] ? 'utf8mb4_bin' : $collation,
                    );
                }
            }

            if ($changes !== []) {
                DB::statement(sprintf('alter table `%s` %s', $table, implode(', ', $changes)));
            }
        }
    }
};
