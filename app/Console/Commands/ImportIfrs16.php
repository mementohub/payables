<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Lease;
use App\Models\LeaseRate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Aduce în registru calculul IFRS 16 al societății, linie cu linie.
 *
 * Fișierul de calcul e sursa auditată: din el vin liniile portofoliului —
 * contractul, treptele de preț, actele adiționale — cu soldurile de la
 * sfârșitul anului trecut, cu evenimentul anului și cu rata fiecăreia. Tot
 * de acolo vin și ratele BNR, ca o linie nouă să-și poată lua rata singură.
 */
class ImportIfrs16 extends Command
{
    protected $signature = 'leases:import {file=database/data/ifrs16-2026.json} {--company=1}';

    protected $description = 'Aduce în registru liniile și ratele din calculul IFRS 16';

    public function handle(): int
    {
        $path = base_path((string) $this->argument('file'));

        if (! is_file($path)) {
            $this->error('Nu găsesc fișierul: '.$path);

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($path), true);
        $company = Company::query()->findOrFail((int) $this->option('company'));

        $lines = 0;
        $rates = 0;

        DB::transaction(function () use ($data, $company, &$lines, &$rates) {
            foreach (($data['lines'] ?? []) as $one) {
                Lease::query()->updateOrCreate(
                    ['company_id' => $company->id, 'line_no' => (int) $one['no']],
                    [
                        'lessor' => (string) $one['name'],
                        'currency' => (string) ($one['currency'] ?? 'EUR'),
                        'monthly_payment' => round((float) $one['payment'], 2),
                        'in_advance' => false,
                        'starts_on' => $one['start'],
                        'ends_on' => $one['end'],
                        'term_months' => (int) $one['months'],
                        'discount_rate' => round((float) $one['rate'], 6),
                        'group_no' => $one['group'] ?? (int) $one['no'],
                        'successor_no' => $one['successor'] ?? null,
                        'event' => $one['event'] ?? null,
                        'event_on' => $one['event_on'] ?? null,
                        'category' => $one['category'] ?? null,
                        'opening_on' => $data['opening_on'] ?? null,
                        'opening_rou' => (float) ($one['opening_rou'] ?? 0),
                        'opening_liability' => (float) ($one['opening_liability'] ?? 0),
                        'monthly_depreciation' => (float) ($one['monthly_depreciation'] ?? 0),
                        'present_value' => (float) ($one['pv_2026'] ?? 0),
                        'status' => Lease::ACTIVE,
                        'exempt' => false,
                        'source' => 'ifrs16',
                        'note' => $one['note'] ?? null,
                    ],
                );

                $lines++;
            }

            foreach (($data['rates'] ?? []) as $one) {
                LeaseRate::query()->updateOrCreate(
                    ['currency' => $one['currency'], 'month' => $one['month'], 'bucket' => $one['bucket']],
                    ['rate' => $one['rate'], 'provisional' => (bool) ($one['provisional'] ?? false)],
                );

                $rates++;
            }
        });

        $this->info(sprintf('%d linii și %d rate, din „%s”.', $lines, $rates, $data['source'] ?? basename($path)));

        return self::SUCCESS;
    }
}
