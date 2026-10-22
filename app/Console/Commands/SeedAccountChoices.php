<?php

namespace App\Console\Commands;

use App\Models\BankStatementLine;
use App\Models\PartnerAccountChoice;
use App\Services\EInvoices\EInvoiceXmlParser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('suppliers:seed-account-choices
    {--months=24 : Cât de departe în urmă se citesc extrasele}
    {--refresh : Rescrie și alegerile făcute în aplicație, nu doar pe cele din extras}')]
#[Description('Pornește memoria conturilor din plățile care chiar s-au făcut, citite din extrasele bancare.')]
class SeedAccountChoices extends Command
{
    public function handle(): int
    {
        $since = Carbon::now()->subMonths((int) $this->option('months'))->toDateString();

        // Pe plățile ieșite, banca scrie în descriere contul beneficiarului:
        // „Nume, CONT RO24UGBI…, OP: 1 …”. Aia e plata care chiar s-a dus,
        // deci cea mai bună memorie pe care o putem avea.
        $lines = BankStatementLine::query()
            ->where('direction', 'outgoing')
            ->whereNotNull('partner_id')
            ->whereNotNull('obs_txt')
            ->where('obs_txt', 'like', '%CONT RO%')
            ->where('data_doc', '>=', $since)
            ->orderBy('data_doc')
            ->get(['partner_id', 'moneda', 'obs_txt', 'data_doc']);

        $this->info($lines->count().' plăți cu cont scris în extras, din '.$since.' încoace.');

        $written = 0;
        $bar = $this->output->createProgressBar($lines->count());

        foreach ($lines as $line) {
            if (preg_match('/\bRO\d{2}[A-Z0-9]{11,30}\b/i', (string) $line->obs_txt, $match) !== 1) {
                $bar->advance();

                continue;
            }

            $iban = strtoupper($match[0]);

            if (! EInvoiceXmlParser::isIban($iban)) {
                $bar->advance();

                continue;
            }

            $currency = PartnerAccountChoice::currencyKey($line->moneda);
            $choice = PartnerAccountChoice::query()
                ->where('partner_id', $line->partner_id)
                ->where('currency', $currency)
                ->first();

            // Alegerea făcută în aplicație e a omului și rămâne a lui — o
            // rescriem doar dacă i se cere pe față. Pe cea venită tot din
            // extras câștigă plata cea mai nouă.
            $keep = $choice !== null && match (true) {
                $choice->source !== 'bank' => ! $this->option('refresh'),
                default => $choice->last_used_at !== null
                    && $choice->last_used_at->gt(Carbon::parse((string) $line->data_doc)),
            };

            if ($keep) {
                $bar->advance();

                continue;
            }

            PartnerAccountChoice::query()->updateOrCreate(
                ['partner_id' => $line->partner_id, 'currency' => $currency],
                [
                    'iban' => $iban,
                    'source' => 'bank',
                    'times_used' => 1,
                    'last_used_at' => $line->data_doc,
                    'last_used_by_id' => null,
                ],
            );

            $written++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info($written.' alegeri scrise din extrase.');

        return self::SUCCESS;
    }
}
