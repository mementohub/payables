<?php

namespace App\Console\Commands;

use App\Jobs\ReadContractFile;
use App\Models\Contract;
use App\Models\ContractFile;
use Illuminate\Console\Command;

/**
 * Citește din nou contracte deja încărcate.
 *
 * Trebuie când se îndreaptă citirea: cele vechi au rămas cu ce-a priceput
 * mașina atunci. Ce-a scris omul cu mâna nu se clintește — se schimbă numai
 * ce pusese tot ea și acum vede altfel.
 */
class RereadContracts extends Command
{
    protected $signature = 'contracts:reread
        {--number=* : Numere de contract anume; fără ele, toate}
        {--only-empty : Numai contractele cărora le lipsește ceva din fișă}
        {--keep : Nu rescrie nimic din ce e deja pus, doar umple golurile}';

    protected $description = 'Citește din nou documentele contractelor și îndreaptă fișele';

    public function handle(): int
    {
        $numbers = (array) $this->option('number');

        $contracts = Contract::query()
            ->when($numbers !== [], fn ($q) => $q->whereIn('number', $numbers))
            ->when($this->option('only-empty'), fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('expires_at')->orWhereNull('signed_at')->orWhereNull('object')->orWhereNull('partner_tax_id')))
            ->with(['files' => fn ($q) => $q->where('kind', ContractFile::KIND_CONTRACT)->orderBy('version')])
            ->get();

        if ($contracts->isEmpty()) {
            $this->warn('Nu e nimic de citit.');

            return self::SUCCESS;
        }

        $afresh = ! $this->option('keep');
        $sent = 0;

        foreach ($contracts as $contract) {
            $file = $contract->files->last();

            if ($file === null) {
                $this->line("  {$contract->number}: n-are document");

                continue;
            }

            ReadContractFile::dispatch($file->id, $afresh);
            $sent++;
        }

        $this->info(sprintf(
            '%d contracte trimise la citit%s. Rezultatul se vede în fișa fiecăruia și în jurnal.',
            $sent,
            $afresh ? ', cu îndreptarea a ce pusese mașina' : ', numai pe golurile din fișă',
        ));

        return self::SUCCESS;
    }
}
