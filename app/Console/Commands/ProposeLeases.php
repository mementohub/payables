<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Lease;
use App\Models\Partner;
use App\Services\Reports\OmcPnlCostReader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('leases:propose
    {--company= : Compania, implicit toate}
    {--year= : Anul din care se citește chiria, implicit cel curent}
    {--months=12 : Pe câte luni se presupune contractul, până se scrie durata adevărată}
    {--min=1000 : Sub atâta chirie pe an, locul nu se propune}')]
#[Description('Propune contracte de închiriere din chiria plătită în OMC, ca registrul IFRS 16 să pornească de la cifre adevărate.')]
class ProposeLeases extends Command
{
    public function handle(OmcPnlCostReader $reader): int
    {
        $year = (int) ($this->option('year') ?: now()->year);
        $companies = $this->option('company')
            ? Company::query()->whereKey((int) $this->option('company'))->get()
            : Company::query()->orderBy('name')->get();

        $written = 0;

        foreach ($companies as $company) {
            $places = $reader->rentsByPlace($company, $year);
            $this->info(sprintf('%s: %d locuri cu chirie în %d.', $company->name, count($places), $year));

            foreach ($places as $place) {
                if (abs($place['total']) < (float) $this->option('min') || trim($place['lessor']) === '') {
                    continue;
                }

                $months = array_filter($place['months'], fn (float $lei) => abs($lei) > 0.5);

                if ($months === []) {
                    continue;
                }

                // Chiria lunară e media lunilor în care chiar s-a plătit:
                // o lună cu două facturi și alta fără n-ar trebui să schimbe
                // contractul.
                $monthly = round(array_sum($months) / count($months), 2);
                $first = min(array_keys($months));

                $starts = Carbon::create($year, $first, 1);
                $ends = $starts->copy()->addMonths(max(1, (int) $this->option('months')))->subDay();

                $exists = Lease::query()
                    ->where('company_id', $company->id)
                    ->where('lessor', $place['lessor'])
                    ->where('location', $place['location'])
                    ->exists();

                if ($exists) {
                    continue;
                }

                Lease::query()->create([
                    'company_id' => $company->id,
                    'partner_id' => Partner::query()->where('company_id', $company->id)->where('name', $place['lessor'])->value('id'),
                    'lessor' => $place['lessor'],
                    'location' => $place['location'],
                    'currency' => 'Lei',
                    'monthly_payment' => $monthly,
                    'starts_on' => $starts->toDateString(),
                    'ends_on' => $ends->toDateString(),
                    'status' => Lease::DRAFT,
                    'source' => 'omc',
                    'note' => sprintf(
                        'Propus din chiria plătită în %d: %s lei pe %d luni. Durata și rata trebuie luate din contract.',
                        $year,
                        number_format($place['total'], 2, ',', '.'),
                        count($months),
                    ),
                ]);

                $written++;
            }
        }

        $this->info($written.' contracte propuse, în starea „ciornă”. Până nu sunt confirmate, raportul nu le socotește.');

        return self::SUCCESS;
    }
}
