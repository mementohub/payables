<?php

namespace App\Console\Commands;

use App\Services\Suppliers\SupplierTrace;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Caută furnizorii adevărați din spatele facturilor refacturate.
 *
 * Se trece lună cu lună: o lună e destul de mică încât interogarea să nu
 * împovăreze baza celeilalte case și destul de mare încât o rezervare și
 * factura hotelului să cadă de obicei împreună.
 */
class TraceSuppliers extends Command
{
    protected $signature = 'invoices:trace-suppliers
        {--months=3 : Câte luni în urmă se caută}
        {--from= : De la ce lună (AAAA-LL), în locul numărului de luni}
        {--cui=* : Numai casele astea}';

    protected $description = 'Scrie în fișa fiecărei facturi refacturate cine e furnizorul adevărat';

    public function handle(SupplierTrace $trace): int
    {
        $houses = (array) config('omc.resellers', []);
        $wanted = (array) $this->option('cui');

        if ($wanted !== []) {
            $houses = array_intersect_key($houses, array_flip($wanted));
        }

        if ($houses === []) {
            $this->warn('Nicio casă de căutat.');

            return self::SUCCESS;
        }

        $start = $this->option('from')
            ? Carbon::createFromFormat('Y-m', (string) $this->option('from'))->startOfMonth()
            : now()->startOfMonth()->subMonths(max(0, (int) $this->option('months') - 1));

        $months = [];

        for ($month = $start->copy(); $month->lessThanOrEqualTo(now()->startOfMonth()); $month->addMonth()) {
            $months[] = $month->copy();
        }

        foreach ($houses as $cui => $house) {
            $this->line("\n<fg=cyan>{$house['label']}</> ({$house['connection']}, după ".$house['basis'].')');

            foreach ($months as $month) {
                $said = $trace->trace((string) $cui, $month->copy()->startOfMonth(), $month->copy()->endOfMonth());

                if ($said['skipped'] !== null) {
                    $this->line(sprintf('  %s  — %s', $month->format('m.Y'), $said['skipped']));

                    continue;
                }

                $this->line(sprintf(
                    '  %s  %5d facturi · %5d cu furnizor găsit (%d%%) · %d legături',
                    $month->format('m.Y'),
                    $said['invoices'],
                    $said['matched'],
                    $said['invoices'] > 0 ? round($said['matched'] / $said['invoices'] * 100) : 0,
                    $said['sources'],
                ));
            }
        }

        return self::SUCCESS;
    }
}
