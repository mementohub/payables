<?php

namespace App\Services\CashFlow;

use App\Models\CharterContract;
use App\Models\EtripSupplier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Lays the cash that actually moved (OMC bank and cash documents) on the
 * report's own lines, so the past weeks read like the forecast ones.
 *
 * Receipts: eTrip receipts by the segment of their booking (B1–B7), OMC
 * receipts from charter counterparties (B10), and what OMC holds beyond
 * that (BX), so the receipts always add up to OMC.
 *
 * Payments, first rule that applies:
 *  1. the counterpart account is a ledger OPEX account (salaries, taxes,
 *     bank fees, dividends) → that OPEX line;
 *  2. a partner set in cashflow.actuals.partner_lines → that line;
 *  3. a charter contract counterparty → C6;
 *  4. a client account as counterpart (411, 419) → CX (client refunds);
 *  5. an eTrip supplier → the line of what it mostly sells (C1–C6);
 *  6. the account the partner's invoices mostly go to: an OPEX invoice
 *     account → that OPEX line, 471 (tourism services) → C5;
 *  7. anything else → CX.
 */
class ActualCashFlowClassifier
{
    /** eTrip supplier category → report line. */
    private const CATEGORY_LINES = [
        'hotel' => 'C1',
        'transfer' => 'C2',
        'insurance' => 'C3',
        'flight' => 'C4',
        'charter' => 'C6',
        'other' => 'C5',
    ];

    /** How a payment was placed on its line, as the cell detail says it. */
    public const RULE_LABELS = [
        'coresp' => 'după contul corespondent',
        'partner' => 'partener setat în configurare',
        'charter' => 'contraparte contract charter',
        'refunds' => 'restituire client (411 / 419)',
        'etrip' => 'furnizor eTrip, după ce vinde',
        'account' => 'după contul facturilor partenerului',
        'other' => 'neclasificat',
    ];

    public function __construct(
        private EtripCashFlowReader $etrip,
        private OmcCashFlowReader $omc,
    ) {}

    /**
     * @param  list<string>  $weeks  the Mondays of the columns, oldest first
     * @param  CarbonImmutable  $to  first day not counted (today)
     * @param  list<string>  $connections  eTrip connections to read receipts from
     * @param  list<array{key: string, basis?: string, accounts: list<string>}>  $opexCatalogue
     * @param  array<string, string>  $segmentLines  booking segment → B line
     * @return array{lines: array<string, list<float>>, classified: array<string, float>, details: list<array<string, mixed>>, _rows: int, _message: string}
     */
    public function classify(array $weeks, CarbonImmutable $to, array $connections, array $opexCatalogue, array $segmentLines): array
    {
        $firstMonday = CarbonImmutable::parse($weeks[0]);
        $column = array_flip($weeks);
        $lines = [];
        $add = function (string $line, string $week, float $lei) use (&$lines, $column, $weeks): void {
            if (! isset($column[$week])) {
                return;
            }

            $lines[$line] ??= array_fill(0, count($weeks), 0.0);
            $lines[$line][$column[$week]] += $lei;
        };

        $flows = $this->omc->weeklyFlowsByPartner($firstMonday, $to);
        $accounts = $this->omc->partnerMainAccounts($firstMonday->subYear(), $to);
        $suppliers = $this->supplierLines($connections, $to->subYear());
        $charter = CharterContract::query()->pluck('counterparty')->filter()->map(fn (string $name) => self::normalize($name))->flip()->all();
        $configured = collect((array) config('cashflow.actuals.partner_lines', []))->mapWithKeys(fn (string $line, string $name) => [self::normalize($name) => $line])->all();
        $ledger = $this->prefixes($opexCatalogue, 'ledger');
        $invoices = $this->prefixes($opexCatalogue, 'invoices');

        $receiptsTotal = array_fill(0, count($weeks), 0.0);
        // What each cell is made of: payments by partner, account and rule.
        $details = [];
        $detail = function (string $line, string $week, string $kind, string $label, float $lei, array $piece) use (&$details, $column): void {
            if (! isset($column[$week])) {
                return;
            }

            $key = implode('|', [$line, $week, $kind, $label, $piece['reference'] ?? '', $piece['group'] ?? '']);
            $details[$key] ??= ['line' => $line, 'week' => $week, 'kind' => $kind, 'label' => $label, 'lei' => 0.0, 'detail' => $piece];
            $details[$key]['lei'] += $lei;
        };
        $classified = ['coresp' => 0.0, 'partner' => 0.0, 'charter' => 0.0, 'refunds' => 0.0, 'etrip' => 0.0, 'account' => 0.0, 'other' => 0.0];

        foreach ($flows as $flow) {
            $partner = $flow['partner'] !== null ? self::normalize($flow['partner']) : null;

            if ($flow['kind'] === 'in') {
                if (isset($column[$flow['week']])) {
                    $receiptsTotal[$column[$flow['week']]] += $flow['lei'];
                }

                if ($partner !== null && isset($charter[$partner])) {
                    $add('B10', $flow['week'], $flow['lei']);
                    $detail('B10', $flow['week'], 'omc_receipt', (string) $flow['partner'], $flow['lei'], ['group' => self::RULE_LABELS['charter'], 'reference' => $flow['coresp'] !== '' ? $flow['coresp'] : null, 'meta' => ['partner' => $flow['partner']]]);
                }

                continue;
            }

            [$line, $rule] = $this->paymentLine($flow, $partner, $ledger, $invoices, $configured, $charter, $suppliers, $accounts);
            $add($line, $flow['week'], $flow['lei']);
            $classified[$rule] += $flow['lei'];
            $detail($line, $flow['week'], 'omc_payment', $flow['partner'] ?? ($flow['coresp'] !== '' ? 'Fără partener – cont '.$flow['coresp'] : 'Fără partener'), $flow['lei'], [
                'group' => self::RULE_LABELS[$rule],
                'reference' => $flow['coresp'] !== '' ? $flow['coresp'] : null,
                'meta' => ['partner' => $flow['partner'], 'coresp' => $flow['coresp'], 'rule' => $rule, 'account' => $flow['partner'] !== null ? ($accounts[(string) $flow['partner']] ?? null) : null],
            ]);
        }

        $receipts = 0;

        foreach ($connections as $connection) {
            foreach ($this->etrip->receiptsByWeek($connection, $firstMonday, $to) as $row) {
                $segment = BookingSegments::of(['segment_type' => $row['segment_type']]);
                $line = $segmentLines[$segment] ?? 'B7';
                $add($line, $row['week'], $row['lei']);
                $receipts += $row['receipts'];
                $detail($line, $row['week'], 'etrip_receipts', 'Încasări eTrip – '.(BookingSegments::LABELS[$segment] ?? $segment), $row['lei'], [
                    'group' => (string) config("etrip.connections.{$connection}", $connection),
                    'reference' => $row['receipts'].' încasări',
                    'meta' => ['connection' => $connection, 'segment' => $segment, 'segment_type' => $row['segment_type'], 'receipts' => $row['receipts']],
                ]);
            }
        }

        // Whatever OMC received beyond the eTrip receipts and the charter seats.
        $explained = array_fill(0, count($weeks), 0.0);

        foreach ([...array_values($segmentLines), 'B10'] as $line) {
            foreach ($lines[$line] ?? [] as $i => $lei) {
                $explained[$i] += $lei;
            }
        }

        $lines['BX'] = array_map(fn (float $total, float $known) => $total - $known, $receiptsTotal, $explained);
        $charterIn = $lines['B10'] ?? array_fill(0, count($weeks), 0.0);

        foreach ($weeks as $i => $week) {
            $detail('BX', $week, 'residual', 'Încasări OMC (bancă + casă, fără transferuri interne)', $receiptsTotal[$i], ['group' => 'Total OMC']);
            $detail('BX', $week, 'residual', 'Minus încasările eTrip alocate pe dosare (B1–B7)', -($explained[$i] - $charterIn[$i]), ['group' => 'Explicate pe alte linii']);
            $detail('BX', $week, 'residual', 'Minus încasările din contracte charter (B10)', -$charterIn[$i], ['group' => 'Explicate pe alte linii']);
        }
        $lines = array_map(fn (array $values) => array_map(fn (float $v) => round($v, 2), $values), $lines);

        $paid = array_sum($classified);
        $share = fn (float $value) => $paid > 0 ? round(100 * $value / $paid) : 0;

        return [
            'lines' => $lines,
            'classified' => array_map(fn (float $v) => round($v, 2), $classified),
            'details' => array_values($details),
            '_rows' => count($flows) + $receipts,
            '_message' => sprintf(
                'OMC bancă + casă din %s până ieri, pe liniile raportului. Plăți: %d%% după contul corespondent (salarii, taxe), %d%% parteneri setați, %d%% charter, %d%% furnizori eTrip, %d%% după contul facturilor, %d%% restituiri clienți, %d%% neclasificate (CX). Încasări: %d încasări eTrip pe segmentul dosarului; restul până la OMC pe BX.',
                $firstMonday->format('d.m.Y'), $share($classified['coresp']), $share($classified['partner']), $share($classified['charter']),
                $share($classified['etrip']), $share($classified['account']), $share($classified['refunds']), $share($classified['other']), $receipts,
            ),
        ];
    }

    /**
     * @param  array{kind: string, partner: ?string, coresp: string, lei: float}  $flow
     * @param  array<string, string>  $ledger
     * @param  array<string, string>  $invoices
     * @param  array<string, string>  $configured
     * @param  array<string, int>  $charter
     * @param  array<string, string>  $suppliers
     * @param  array<string, string>  $accounts
     * @return array{0: string, 1: string} the line and the rule that placed it
     */
    private function paymentLine(array $flow, ?string $partner, array $ledger, array $invoices, array $configured, array $charter, array $suppliers, array $accounts): array
    {
        if (($key = self::match($flow['coresp'], $ledger)) !== null) {
            return [$key, 'coresp'];
        }

        if ($partner === null) {
            $key = self::match($flow['coresp'], $invoices);

            return $key !== null ? [$key, 'coresp'] : ['CX', 'other'];
        }

        if (isset($configured[$partner])) {
            return [$configured[$partner], 'partner'];
        }

        if (isset($charter[$partner])) {
            return ['C6', 'charter'];
        }

        if (str_starts_with($flow['coresp'], '411') || str_starts_with($flow['coresp'], '419')) {
            return ['CX', 'refunds'];
        }

        if (isset($suppliers[$partner])) {
            return [$suppliers[$partner], 'etrip'];
        }

        $account = $accounts[(string) $flow['partner']] ?? null;

        if ($account !== null && ($key = self::match($account, $invoices)) !== null) {
            return [$key, 'account'];
        }

        if ($account !== null && str_starts_with($account, '471')) {
            return ['C5', 'account'];
        }

        return ['CX', 'other'];
    }

    /**
     * OMC partner (normalised name) → the line of what it sells in eTrip:
     * through the links kept on the Suppliers page, then by name.
     *
     * @param  list<string>  $connections
     * @return array<string, string>
     */
    private function supplierLines(array $connections, CarbonImmutable $since): array
    {
        $byCode = [];
        $byName = [];

        foreach ($connections as $connection) {
            foreach ($this->etrip->supplierCategories($connection, $since) as $supplier) {
                $line = self::CATEGORY_LINES[$supplier['category']] ?? 'C5';
                $byCode[$connection.'|'.$supplier['code']] = $line;
                $byName[self::normalize($supplier['name'])] ??= $line;
            }
        }

        $linked = [];

        EtripSupplier::query()
            ->whereNotNull('partner_id')
            ->with('partner:id,name')
            ->get(['id', 'etrip_connection', 'code', 'partner_id'])
            ->each(function (EtripSupplier $supplier) use ($byCode, &$linked) {
                $line = $byCode[$supplier->etrip_connection.'|'.$supplier->code] ?? null;

                if ($line !== null && $supplier->partner !== null) {
                    $linked[self::normalize($supplier->partner->name)] ??= $line;
                }
            });

        return [...$byName, ...$linked];
    }

    /**
     * Account prefix → OPEX category key, for the categories of one basis.
     *
     * @param  list<array{key: string, basis?: string, accounts: list<string>}>  $catalogue
     * @return array<string, string>
     */
    private function prefixes(array $catalogue, string $basis): array
    {
        $prefixes = [];

        foreach ($catalogue as $category) {
            if (($category['basis'] ?? 'invoices') === $basis) {
                foreach ((array) $category['accounts'] as $account) {
                    $prefixes[(string) $account] = $category['key'];
                }
            }
        }

        // Longest prefix first, so 4411 wins over 441 and 6651 over 66.
        uksort($prefixes, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return $prefixes;
    }

    /**
     * @param  array<string, string>  $prefixes
     */
    private static function match(string $account, array $prefixes): ?string
    {
        foreach ($prefixes as $prefix => $key) {
            if ($account !== '' && str_starts_with($account, $prefix)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * A partner name as the two systems can agree on it: no diacritics,
     * no legal form, no bracketed note ("Hotelbeds (TCT)" → "hotelbeds").
     */
    public static function normalize(string $name): string
    {
        $name = Str::lower(Str::ascii($name));
        $name = (string) preg_replace('/\([^)]*\)/', ' ', $name);
        $name = (string) preg_replace('/[^a-z0-9]+/', ' ', $name);
        $name = (string) preg_replace('/\b(s ?c|s ?r ?l|s ?a|ltd|limited|gmbh|llc|srl|sa|sc)\b/', ' ', $name);

        return trim((string) preg_replace('/\s+/', ' ', $name));
    }
}
