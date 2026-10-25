<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Forecast\ForecastHistoryBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('forecast:history
    {--company= : Compania, implicit toate}
    {--years=3 : Câți ani în urmă, numărând și anul curent}
    {--year=* : Anumiți ani, în locul celor din urmă}')]
#[Description('Strânge istoricul pe categorii și pe curse, din care se judecă planul.')]
class BuildForecastHistory extends Command
{
    public function handle(ForecastHistoryBuilder $builder): int
    {
        $years = $this->option('year')
            ? array_map('intval', (array) $this->option('year'))
            : range((int) now()->year, (int) now()->year - (int) $this->option('years') + 1);

        $companies = $this->option('company')
            ? Company::query()->whereKey((int) $this->option('company'))->get()
            : Company::query()->orderBy('name')->get();

        foreach ($companies as $company) {
            foreach ($years as $year) {
                $started = microtime(true);

                try {
                    $out = $builder->build($company, $year);
                } catch (Throwable $e) {
                    $this->error(sprintf('%s %d: %s', $company->name, $year, $e->getMessage()));

                    continue;
                }

                $this->info(sprintf(
                    '%s %d: %d rânduri pe categorii, %d pe curse (%ds).',
                    $company->name, $year, $out['categories'], $out['charter'], (int) (microtime(true) - $started),
                ));
            }
        }

        return self::SUCCESS;
    }
}
