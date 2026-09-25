<?php

namespace App\Services\Reports;

use App\Services\Etrip\EtripReader;

/**
 * Punctul de lucru de pe o cheltuială OMC → canalul de vânzare care o suportă.
 *
 * Doar cheltuielile care se pot lipi de un sediu real se alocă direct (chiria
 * de la Sun Plaza e a magazinului Sun Plaza, deci a retailului B2C). Restul
 * rămân generale și se împart pe cheia de venit.
 *
 * `eu_punct_lucru` din OMC e text liber: pe lângă sedii adevărate ține și
 * etichete de cost („Tichete masa”, „Facebook”, „Contabilitate”) și locuri de
 * înregistrare contabilă. De aceea potrivirea cu sucursalele eTrip e pe nume
 * normalizat întreg, nu pe fragment: „CLUJ” de pe statul de plată nu e
 * sucursala „Cluj Iulius”, iar o potrivire pe bucăți ar muta salariile
 * companiei pe un magazin.
 */
class PnlBranchMap
{
    /** @var array<string, string>|null */
    private ?array $branches = null;

    /** @var array<string, string>|null */
    private ?array $labels = null;

    public function __construct(private EtripReader $etrip) {}

    /**
     * Canalul pe care cade un punct de lucru, sau null dacă e o cheltuială
     * generală.
     */
    public function channel(string $sediu): ?string
    {
        $key = EtripPnlReader::normalise($sediu);

        if ($key === '') {
            return null;
        }

        if (str_contains($key, 'franciza')) {
            return 'franciza';
        }

        return $this->branches()[$key] ?? null;
    }

    /**
     * Sucursala eTrip pe care cade un punct de lucru din OMC, cu numele ei de
     * acolo — cheltuiala unui magazin trebuie să ajungă pe magazinul acela, nu
     * doar pe canalul lui.
     */
    public function branchFor(string $sediu): ?string
    {
        $key = EtripPnlReader::normalise($sediu);

        return $key === '' ? null : ($this->labels()[$key] ?? null);
    }

    /**
     * Magazinul de pe analiticul unui stat de plată: „.1.Plaza”, „.Sun Plaza”,
     * „.IASI Moldova Mall”.
     *
     * Potrivirea e mai largă decât la punctele de lucru fiindcă analiticul are
     * prefixe de numerotare, dar tot pe nume întreg: se taie cifrele de la
     * început, apoi se cere fie egalitate, fie ca unul să înceapă cu celălalt
     * pe cel puțin cinci litere. „.Contabilitate” sau „.Soferi” nu prind nimic,
     * și e bine: nu sunt magazine.
     */
    public function branchForAnalytic(string $analytic): ?string
    {
        $key = preg_replace('/^\d+/', '', EtripPnlReader::normalise($analytic));

        if ($key === null || strlen($key) < 4) {
            return null;
        }

        $labels = $this->labels();

        if (isset($labels[$key])) {
            return $labels[$key];
        }

        foreach ($labels as $branchKey => $name) {
            if (strlen($branchKey) >= 5 && (str_starts_with($key, $branchKey) || str_starts_with($branchKey, $key))) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Numele sucursalelor, pe cheia lor normalizată.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        if ($this->labels !== null) {
            return $this->labels;
        }

        $labels = [];

        foreach ($this->names((string) config('pnl.etrip_connection', 'etrip_chr')) as $name) {
            $key = EtripPnlReader::normalise($name);

            if ($key !== '') {
                $labels[$key] ??= $name;
            }
        }

        return $this->labels = $labels;
    }

    /**
     * Sucursalele eTrip, normalizate, cu canalul fiecăreia. Sucursalele de
     * backoffice nu intră: cheltuiala lor e generală, ca și vânzarea lor.
     *
     * @return array<string, string>
     */
    public function branches(): array
    {
        if ($this->branches !== null) {
            return $this->branches;
        }

        $segmentation = (array) config('pnl.segmentation');
        $connection = (string) config('pnl.etrip_connection', 'etrip_chr');

        $fragments = fn (string $key) => array_values(array_filter(array_map(
            fn ($value) => EtripPnlReader::normalise((string) $value),
            (array) ($segmentation[$key] ?? []),
        )));

        $siteBranches = $fragments('site_branches');
        $ccBranches = $fragments('cc_branches');
        $b2bBranches = $fragments('b2b_branches');
        $otherBranches = $fragments('other_branches');
        $operational = $fragments('operational_branches');

        $map = [];

        foreach ($this->names($connection) as $name) {
            $key = EtripPnlReader::normalise($name);

            if ($key === '' || in_array($key, $operational, true)) {
                continue;
            }

            $matches = fn (array $list) => array_filter($list, fn (string $f) => $f !== '' && str_contains($key, $f)) !== [];

            $map[$key] = match (true) {
                // O sucursală „Rezervari Site” care vinde cu oameni e retail de sediu.
                $matches($siteBranches) => 'retail',
                $matches($ccBranches) => 'cc',
                $matches($b2bBranches) => 'b2b',
                str_contains($key, 'franciza') => 'franciza',
                // Cursul de ghid, corporate, ticketingul, abuela.ro: activități
                // proprii, dar nu magazine. Aceleași reguli ca la venit,
                // altfel cheltuiala lor ar rămâne pe retail.
                in_array($key, $otherBranches, true) => 'other',
                default => 'retail',
            };
        }

        return $this->branches = $map;
    }

    /**
     * @return list<string>
     */
    private function names(string $connection): array
    {
        $rows = $this->etrip->connection($connection)->select('SELECT name FROM settings.branch_offices WHERE name IS NOT NULL');

        return array_map(fn ($row) => (string) $row->name, $rows);
    }
}
