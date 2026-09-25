<?php

namespace App\Services\Reports;

use App\Models\PnlCostOverride;

/**
 * Traduce o cheltuială din OMC într-o linie a contului de profit și pierdere
 * (structura din „Test template”: 26 grupe, fiecare cu liniile ei „Saf line”).
 *
 * OMC nu ține structura asta nicăieri, așa că linia se deduce din trei semnale,
 * în ordinea încrederii:
 *
 *  1. **contul contabil** (`conts` / `conts_db`) — singurul semn sigur: 6021 e
 *     combustibil, 613 asigurări, 641 salarii, oricine ar fi scris factura;
 *  2. **textul** — categoria de cheltuială, punctul de lucru și partenerul,
 *     normalizate, pentru a alege linia din interiorul grupei (contul 612 spune
 *     „chirie”, textul spune dacă e chiria unui sediu sau a unei imprimante);
 *  3. **nimic** — cheltuiala rămâne `nemapat` și se vede ca atare în raport.
 *
 * Ce nu se potrivește NU se aruncă niciodată: altfel P&L-ul n-ar mai închide cu
 * totalul companiei, care e singura verificare care contează.
 */
class PnlCostMap
{
    /** Linia pe care cad cheltuielile pe care regulile nu le recunosc. */
    public const UNMAPPED = 'nemapat';

    public const UNMAPPED_GROUP = 'Nemapate';

    /**
     * Reguli de text, în ordinea specificității: prima care se potrivește
     * câștigă. `any` = fragmente normalizate căutate în textul cheltuielii,
     * `accounts` = prefixe de cont care restrâng regula.
     *
     * @var list<array{saf: string, any: list<string>, accounts?: list<string>}>
     */
    private const TEXT_RULES = [
        // Salarii și colaboratori — cheltuiala pe care se sparg apoi toate cheile.
        ['saf' => '20300', 'any' => ['pfa', 'srl colaborare', 'colaborator', 'drepturiautor'], 'accounts' => ['621', '628']],
        ['saf' => '20200', 'any' => ['salarii', 'salariu', 'salariale']],
        ['saf' => '20100', 'any' => ['prime', 'bonus']],
        ['saf' => '20400', 'any' => ['suportfinanciar']],
        ['saf' => '11000', 'any' => ['drepturiautor']],

        // Chirii: sediile intră la „Chirie sedii”, restul la „Inchirieri”.
        ['saf' => '4010', 'any' => ['chiriemarketing', 'chiriipublicitare', 'panotaj', 'panouripublicitare']],
        ['saf' => '4020', 'any' => ['spatiicomune', 'servicecharge']],
        ['saf' => '12000', 'any' => ['chirieauto', 'chiriiauto', 'inchirieriauto', 'rentacar', 'carrental']],
        ['saf' => '12100', 'any' => ['container']],
        ['saf' => '12200', 'any' => ['imprimant', 'xerox', 'copiator']],
        ['saf' => '12300', 'any' => ['purificator']],
        ['saf' => '12400', 'any' => ['server', 'colocare', 'hosting']],
        ['saf' => '4000', 'any' => ['chirie', 'chirii', 'rental', 'rent'], 'accounts' => ['612']],

        // Utilități, telecom.
        ['saf' => '26000', 'any' => ['apa']],
        ['saf' => '26100', 'any' => ['energie', 'electric', 'curent']],
        ['saf' => '26200', 'any' => ['gaz']],
        ['saf' => '24200', 'any' => ['telefon', 'mobilephone', 'telefonie']],
        ['saf' => '14000', 'any' => ['internet', 'fixedphone', 'vdf']],
        ['saf' => '24100', 'any' => ['curierat', 'curier', 'fan courier', 'dhl']],
        ['saf' => '24000', 'any' => ['colocare']],

        // Audit și servicii profesionale — înaintea marketingului, ca un nume de
        // firmă („PriceWaterhouse”) să nu fie citit ca un fragment de reclamă.
        ['saf' => '18500', 'any' => ['audit', 'kpmg', 'pricewaterhouse', 'pwc', 'deloitte', 'ernstyoung']],

        // Marketing și promovare.
        ['saf' => '16940', 'any' => ['facebook', 'meta ads']],
        ['saf' => '16950', 'any' => ['google', 'adwords', 'awards']],
        ['saf' => '16970', 'any' => ['tv', 'televiziune']],
        ['saf' => '16980', 'any' => ['radio']],
        ['saf' => '16990', 'any' => ['serviciipr', 'relatiipublice', 'agentiepr']],
        ['saf' => '16700', 'any' => ['catalog']],
        ['saf' => '16920', 'any' => ['video', 'productievideo']],
        ['saf' => '16910', 'any' => ['productieauto']],
        ['saf' => '16600', 'any' => ['printare', 'tiparire', 'tipografie']],
        ['saf' => '16500', 'any' => ['panotaj']],
        ['saf' => '16400', 'any' => ['targ', 'workshop', 'roadshow', 'caravana', 'expozitie']],
        ['saf' => '16200', 'any' => ['abonament'], 'accounts' => ['623', '6232']],
        ['saf' => '16100', 'any' => ['barter']],
        ['saf' => '16960', 'any' => ['online', 'digital', 'seo', 'ppc', 'tiktok', 'promovare']],

        // Protocol.
        ['saf' => '19200', 'any' => ['cazare'], 'accounts' => ['623', '6231']],
        ['saf' => '19300', 'any' => ['deplasare'], 'accounts' => ['623', '6231']],
        ['saf' => '19400', 'any' => ['eveniment'], 'accounts' => ['623', '6231']],
        ['saf' => '19100', 'any' => ['campanie'], 'accounts' => ['623', '6231']],

        // Servicii legale și consultanță.
        ['saf' => '21200', 'any' => ['avocat', 'onorariuavocati', 'cabinet']],
        ['saf' => '21300', 'any' => ['notar', 'notarial']],
        ['saf' => '21100', 'any' => ['juridic', 'legal', 'litigi', 'executor']],
        ['saf' => '18920', 'any' => ['consultanta', 'consulting']],
        ['saf' => '18400', 'any' => ['arhiva', 'arhivare']],
        ['saf' => '18600', 'any' => ['callcenter']],
        ['saf' => '18700', 'any' => ['medicinamuncii', 'ssm', 'securitateinmunca']],
        ['saf' => '18900', 'any' => ['recrutare', 'trendcareer']],
        ['saf' => '18910', 'any' => ['salubriz', 'gunoi', 'deseu']],
        ['saf' => '18930', 'any' => ['software', 'licenta', 'licente', 'erp', 'it-trip', 'ittrip', 'softmediatel', 'irix']],
        ['saf' => '18940', 'any' => ['training', 'instruire']],
        ['saf' => '18300', 'any' => ['abonament']],
        ['saf' => '18100', 'any' => ['comision'], 'accounts' => ['622', '628']],

        // Asigurări.
        ['saf' => '3000', 'any' => ['insolventa']],
        ['saf' => '3010', 'any' => ['rca', 'casco', 'asigurareauto']],
        ['saf' => '3030', 'any' => ['asigurarecalatorie', 'asigurarideacalatorie', 'storno']],
        ['saf' => '3050', 'any' => ['incendiu']],
        ['saf' => '3060', 'any' => ['raspunderecivila']],

        // Transport, combustibil, mașini.
        ['saf' => '5010', 'any' => ['combustibil', 'carburant', 'motorina', 'benzina', 'combustible']],
        // „Transport” și „deplasare” apar în explicația multor facturi care n-au
        // nicio treabă cu ele — pe un decont de deplasare (625) scrie de obicei
        // și unde s-a mers. Fără conturi, regula fura decontul de la „Deplasare
        // și diurnă” și îl punea la „Transport”: 231.794 lei numai în iulie.
        ['saf' => '25200', 'any' => ['transport'], 'accounts' => ['624', '628', '6022', '613']],
        ['saf' => '25100', 'any' => ['deplasare'], 'accounts' => ['624', '628']],
        // „Service” e în numele multor furnizori care n-au nicio legătură cu
        // întreținerea — DKV EURO SERVICE vinde motorină. Pe contul de
        // combustibil, regula transforma 322.879 lei de carburant, într-o
        // singură lună, în reparații.
        ['saf' => '15000', 'any' => ['mentenanta', 'reparatie', 'reparatii', 'piese', 'service', 'maintenance'], 'accounts' => ['611', '615', '6024', '628', '6028', '603']],

        // Taxe, amenzi, bancar.
        ['saf' => '23100', 'any' => ['impozitpeprofit']],
        ['saf' => '23200', 'any' => ['impozit']],
        // Cuvântul „taxă” apare pe orice, de la chirie la factura de curent;
        // contul spune dacă e chiar o taxă. 43.488 lei de chirie ajunseseră la
        // „Taxe” doar fiindcă scria „taxa” în explicație.
        ['saf' => '23300', 'any' => ['taxa', 'taxe', 'cotizatie', 'anat', 'autorizatie', 'iso', 'srac'], 'accounts' => ['635', '6588', '658', '622', '628']],
        ['saf' => '2000', 'any' => ['amenda', 'amenzi']],
        ['saf' => '2030', 'any' => ['penalitatedeintarziere', 'penalitatiintarziere']],
        ['saf' => '2050', 'any' => ['penalitatezbor']],
        ['saf' => '2020', 'any' => ['penalitate', 'penalitati']],
        ['saf' => '6010', 'any' => ['comisionbancar', 'comisioanebancare']],

        // Sedii, evenimente interne, obiecte de inventar.
        ['saf' => '1030', 'any' => ['deschideresediu', 'deschiderisedii']],
        ['saf' => '9000', 'any' => ['deschideresediu', 'deschiderisedii']],
        ['saf' => '1060', 'any' => ['relocare']],
        ['saf' => '1070', 'any' => ['teambuilding']],
        ['saf' => '1020', 'any' => ['curatatorie', 'curatenie']],
        ['saf' => '1050', 'any' => ['domeniu', 'domenii']],
        ['saf' => '17100', 'any' => ['mijlocfix', 'mijloacefixe']],
        ['saf' => '17101', 'any' => ['consumabil']],
        ['saf' => '17000', 'any' => ['inventoryitems', 'obiecteinventar']],
        ['saf' => '13000', 'any' => ['infotrip']],
        ['saf' => '22000', 'any' => ['sponsoriz']],
        ['saf' => '8010', 'any' => ['diurna', 'diurne']],
        ['saf' => '10000', 'any' => ['dobanda', 'dobanzi']],
    ];

    /**
     * Contul contabil → linia implicită a grupei lui. Se folosește când textul
     * nu spune nimic; prefixele mai lungi au prioritate.
     *
     * @var array<string, string>
     */
    private const ACCOUNT_RULES = [
        '6021' => '5010', '6022' => '5010', '6024' => '17000', '6028' => '7000',
        '603' => '17000', '604' => '7000', '605' => '26100', '6051' => '26100',
        '6052' => '26000', '6053' => '26200', '6058' => '26100',
        '609' => '7000', '611' => '15000', '612' => '4000', '6123' => '4000',
        '613' => '3000', '615' => '15000', '617' => '18200',
        '621' => '20300', '622' => '18100', '623' => '16000', '6231' => '19000',
        '6232' => '16000', '624' => '25200', '625' => '8010', '626' => '24200',
        '627' => '6010', '628' => '18200', '635' => '23300',
        '641' => '20200', '642' => '20000', '645' => '20000', '646' => '20000',
        '654' => '18000', '658' => '18000', '6581' => '2020', '6582' => '22000',
        '665' => '18000', '666' => '10000', '667' => '18000', '668' => '18000',
        '681' => '18000',
    ];

    /** @var array<string, array{group: string, label: string}>|null */
    private ?array $lines = null;

    /**
     * Corecturi făcute de om, pe semnătură de cheltuială și pe linie. Fiecare
     * poate schimba linia, canalul, categoria de produs, sau oricare
     * combinație a lor.
     *
     * @var array{item: array<string, array{saf: ?string, channel: ?string, product: ?string}>, line: array<string, array{saf: ?string, channel: ?string, product: ?string}>}
     */
    private array $overrides = ['item' => [], 'line' => []];

    /**
     * Toate liniile P&L, pe cod „Saf line”.
     *
     * @return array<string, array{group: string, label: string}>
     */
    public function lines(): array
    {
        if ($this->lines !== null) {
            return $this->lines;
        }

        $lines = [];

        foreach ((array) config('pnl_lines', []) as $group => $leaves) {
            foreach ((array) $leaves as $saf => $label) {
                $lines[(string) $saf] = ['group' => (string) $group, 'label' => (string) $label];
            }
        }

        $lines[self::UNMAPPED] = ['group' => self::UNMAPPED_GROUP, 'label' => 'Cheltuieli nemapate'];

        return $this->lines = $lines;
    }

    /**
     * Linia cu codul dat, gata de folosit ca rezultat de mapare.
     *
     * @return array{saf: string, group: string, label: string, matched_by: string, channel: ?string, product: ?string}
     */
    public function lineFor(string $saf): array
    {
        return [
            ...$this->line($saf, 'corectură'),
            'channel' => $this->channelForLine($saf),
            'product' => $this->productForLine($saf),
        ];
    }

    /**
     * Canalul cerut pentru toată linia, dacă cineva a mutat-o.
     */
    private function channelForLine(string $saf): ?string
    {
        return $this->overrides['line'][$saf]['channel'] ?? null;
    }

    /**
     * Categoria de produs cerută pentru toată linia, dacă cineva a mutat-o.
     */
    private function productForLine(string $saf): ?string
    {
        return $this->overrides['line'][$saf]['product'] ?? null;
    }

    /**
     * Grupele, în ordinea din template, cu liniile fiecăreia.
     *
     * @return array<string, list<string>>
     */
    public function groups(): array
    {
        $groups = [];

        foreach ($this->lines() as $saf => $line) {
            $groups[$line['group']][] = $saf;
        }

        return $groups;
    }

    /**
     * Încarcă corecturile făcute de om pentru o companie. Fără ele, maparea e
     * doar pe reguli.
     *
     * @param  iterable<PnlCostOverride>  $overrides
     */
    public function withOverrides(iterable $overrides): static
    {
        $this->overrides = ['item' => [], 'line' => []];

        foreach ($overrides as $override) {
            if (! array_key_exists($override->scope, $this->overrides)) {
                continue;
            }

            $this->overrides[$override->scope][$override->match_key] = [
                'saf' => $override->saf !== null ? (string) $override->saf : null,
                'channel' => $override->channel !== null ? (string) $override->channel : null,
                'product' => $override->product !== null ? (string) $override->product : null,
            ];
        }

        return $this;
    }

    /**
     * Linia P&L a unei cheltuieli. `matched_by` spune pe ce s-a sprijinit
     * decizia, ca raportul să poată arăta cât din total e mapat sigur.
     *
     * Semnătura `$sediu` / `$partner` contează: pe ea se prind corecturile de
     * `item`, deci se dau separat, nu ca text oarecare.
     *
     * @return array{saf: string, group: string, label: string, matched_by: string, channel: ?string, product: ?string}
     */
    public function map(?string $account, string ...$text): array
    {
        $account = trim((string) $account);
        $haystack = $this->normalise(implode(' ', $text));

        // Corectura pe cheltuiala exactă bate și regulile, și corectura de linie.
        $itemKey = PnlCostOverride::itemKey($account, $text[1] ?? '', $text[2] ?? '');
        $item = $this->overrides['item'][$itemKey] ?? null;

        if ($item !== null && $item['saf'] !== null) {
            return [...$this->line($item['saf'], 'corectură'), 'channel' => $item['channel'], 'product' => $item['product']];
        }

        foreach (self::TEXT_RULES as $rule) {
            if (! $this->accountAllowed($account, $rule['accounts'] ?? null)) {
                continue;
            }

            foreach ($rule['any'] as $needle) {
                if ($needle !== '' && str_contains($haystack, $this->normalise($needle))) {
                    $line = $this->line($rule['saf'], 'text');

                    return [
                        ...$line,
                        'channel' => $item['channel'] ?? $this->channelForLine($line['saf']),
                        'product' => $item['product'] ?? $this->productForLine($line['saf']),
                    ];
                }
            }
        }

        $found = null;

        for ($length = min(4, strlen($account)); $length >= 3 && $found === null; $length--) {
            $prefix = substr($account, 0, $length);

            if (isset(self::ACCOUNT_RULES[$prefix])) {
                $found = $this->line(self::ACCOUNT_RULES[$prefix], 'cont');
            }
        }

        $found ??= $this->line(self::UNMAPPED, 'nimic');

        return [
            ...$found,
            'channel' => $item['channel'] ?? $this->channelForLine($found['saf']),
            'product' => $item['product'] ?? $this->productForLine($found['saf']),
        ];
    }

    /**
     * @param  list<string>|null  $accounts
     */
    private function accountAllowed(string $account, ?array $accounts): bool
    {
        if ($accounts === null) {
            return true;
        }

        foreach ($accounts as $prefix) {
            if ($account !== '' && str_starts_with($account, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{saf: string, group: string, label: string, matched_by: string}
     */
    private function line(string $saf, string $matchedBy): array
    {
        if ($matchedBy !== 'corectură' && ($this->overrides['line'][$saf]['saf'] ?? null) !== null) {
            $saf = $this->overrides['line'][$saf]['saf'];
            $matchedBy = 'corectură';
        }

        $lines = $this->lines();

        if (! isset($lines[$saf])) {
            $saf = self::UNMAPPED;
            $matchedBy = 'nimic';
        }

        return ['saf' => $saf, 'group' => $lines[$saf]['group'], 'label' => $lines[$saf]['label'], 'matched_by' => $matchedBy];
    }

    private function normalise(string $value): string
    {
        return EtripPnlReader::normalise($value);
    }
}
