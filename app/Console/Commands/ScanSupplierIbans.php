<?php

namespace App\Console\Commands;

use App\Models\PartnerEInvoiceAccount;
use App\Services\EInvoices\SupplierIbanHarvester;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('suppliers:scan-ibans
    {--months=12 : Cât de departe în urmă se uită după facturi}
    {--limit= : Câți furnizori se caută într-o trecere}
    {--fresh : Caută din nou și la cei care au deja un cont din e-Factură}')]
#[Description('Caută în e-Facturi conturile furnizorilor care n-au niciun IBAN în OMC.')]
class ScanSupplierIbans extends Command
{
    public function handle(SupplierIbanHarvester $harvester): int
    {
        $partners = $harvester->missing((int) $this->option('months'));

        if (! $this->option('fresh')) {
            $known = PartnerEInvoiceAccount::query()->pluck('partner_id')->unique()->all();
            $partners = $partners->whereNotIn('id', $known)->values();
        }

        if ($this->option('limit')) {
            $partners = $partners->take((int) $this->option('limit'));
        }

        if ($partners->isEmpty()) {
            $this->info('Niciun furnizor de căutat.');

            return self::SUCCESS;
        }

        $found = 0;
        $withAccount = 0;
        $bar = $this->output->createProgressBar($partners->count());

        foreach ($partners as $partner) {
            $written = $harvester->forPartner($partner);
            $found += $written;
            $withAccount += $written > 0 ? 1 : 0;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $this->info(sprintf(
            '%d furnizori căutați, %d cu cont găsit în e-Factură, %d conturi noi.',
            $partners->count(),
            $withAccount,
            $found,
        ));

        return self::SUCCESS;
    }
}
