<?php

namespace App\Services\CashFlow;

use App\Models\CashFlowDetail;
use App\Models\CashFlowSnapshot;
use App\Models\Invoice;
use App\Models\Partner;
use App\Services\Etrip\CheckinCostCheckService;
use App\Services\Omc\OmcReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What a cell of a cash-flow snapshot is made of, as the build recorded it:
 * the pieces of one line over one or more weeks (a month in the monthly
 * view), with what each one is and where to open it.
 */
class CashFlowCellDetails
{
    public const MAX_ROWS = 600;

    public function __construct(private OmcReader $omc) {}

    /**
     * @param  list<string>  $weeks  Mondays
     * @return array<string, mixed>
     */
    public function cell(CashFlowSnapshot $snapshot, string $line, array $weeks, bool $actual): array
    {
        $available = CashFlowDetail::query()->where('cash_flow_snapshot_id', $snapshot->id)->exists();

        $pieces = CashFlowDetail::query()
            ->where('cash_flow_snapshot_id', $snapshot->id)
            ->where('line', $line)
            ->where('actual', $actual)
            ->whereIn('week', $weeks)
            ->get();

        $total = round((float) $pieces->sum('lei'), 2);
        $sorted = $pieces->sortByDesc(fn (CashFlowDetail $piece) => abs($piece->lei))->values();
        $shown = $sorted->take(self::MAX_ROWS);
        $links = $this->links($shown, $snapshot);

        return [
            'available' => $available,
            'line' => $line,
            'weeks' => $weeks,
            'actual' => $actual,
            'total' => $total,
            'count' => $pieces->count(),
            'groups' => $pieces
                ->groupBy(fn (CashFlowDetail $piece) => $piece->group ?? '')
                ->map(fn (Collection $group, string $label) => [
                    'label' => $label !== '' ? $label : null,
                    'lei' => round((float) $group->sum('lei'), 2),
                    'count' => $group->count(),
                ])
                ->sortByDesc(fn (array $group) => abs($group['lei']))
                ->values()
                ->all(),
            'by_currency' => $pieces
                ->whereNotNull('currency')
                ->groupBy('currency')
                ->map(fn (Collection $group, string $currency) => [
                    'currency' => $currency,
                    'amount' => round((float) $group->sum('amount'), 2),
                    'lei' => round((float) $group->sum('lei'), 2),
                ])
                ->sortByDesc(fn (array $row) => abs($row['lei']))
                ->values()
                ->all(),
            'rows' => $shown->map(fn (CashFlowDetail $piece) => $this->row($piece, $links[$piece->id] ?? [], $snapshot))->all(),
            'explanation' => $this->explanation($line, $actual, (array) ($snapshot->payload['params'] ?? [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function row(CashFlowDetail $piece, array $extra, CashFlowSnapshot $snapshot): array
    {
        $meta = $piece->meta ?? [];

        return [
            'id' => $piece->id,
            'week' => $piece->week->toDateString(),
            'kind' => $piece->kind,
            'group' => $piece->group,
            'label' => $piece->label,
            'reference' => $piece->reference,
            'date' => $piece->date?->toDateString(),
            'currency' => $piece->currency,
            'amount' => $piece->amount,
            'lei' => $piece->lei,
            'note' => $this->note($piece, $meta),
            'link' => $extra['link'] ?? null,
            'status' => $extra['status'] ?? null,
            'department' => $extra['department'] ?? null,
            'expand' => $this->expand($piece, $meta, $snapshot),
        ];
    }

    /**
     * One line saying how the piece weighs in the cell.
     *
     * @param  array<string, mixed>  $meta
     */
    private function note(CashFlowDetail $piece, array $meta): ?string
    {
        $share = isset($meta['share']) && (float) $meta['share'] < 1 ? $this->fraction((float) $meta['share']) : null;

        return match ($piece->kind) {
            'tranche' => sprintf('%s · plecare %s%s', $piece->group === 'avans' ? 'avans programat' : 'sold', $this->dmy($meta['start_date'] ?? null), isset($meta['total_due']) ? sprintf(' · de plată %s, încasat %s', $this->money($meta['total_due']), $this->money($meta['paid'] ?? 0)) : ''),
            'overdue' => sprintf('scadent de %d zile · %s%% din restanță, pe %d săpt. = %s%% pe săptămână', (int) ($meta['days_overdue'] ?? 0), $this->money($meta['pct'] ?? 0), (int) ($meta['weeks'] ?? 1), $this->money((float) ($meta['share'] ?? 0) * 100)),
            'services' => sprintf('%d servicii%s · check-in %s – %s · plata cu %d zile înainte%s', (int) ($meta['items'] ?? 0), isset($meta['bookings']) ? ', '.(int) $meta['bookings'].' dosare' : '', $this->dmy($meta['checkin_from'] ?? null), $this->dmy($meta['checkin_to'] ?? null), (int) ($meta['days_before'] ?? 0), isset($meta['factor']) && (float) $meta['factor'] < 1 ? sprintf(' · %s%% (restul plătit în avans)', $this->money((float) $meta['factor'] * 100)) : ''),
            'tickets' => sprintf('%d bilete comandate în ultimele %d zile, plătite imediat', (int) ($meta['items'] ?? 0), (int) ($meta['ticket_days'] ?? 0)),
            'rotation' => sprintf('zbor %s · net %s%s%s', $this->dmy($meta['flight_date'] ?? null), $this->money($meta['net'] ?? 0), ! empty($meta['taxes']) ? ' + taxe '.$this->money($meta['taxes']) : '', ! empty($meta['deposit_covered']) ? ' − acoperit din depozit '.$this->money($meta['deposit_covered']) : ''),
            'airport_taxes' => sprintf('taxe aeroport pentru zborul din %s', $this->dmy($meta['flight_date'] ?? null)),
            'deposit' => 'depozitul contractului, la scadența lui',
            'estimate', 'estimate_taxes' => sprintf('zborul din %s decalat 364 de zile × %s', $this->dmy($meta['flight_date'] ?? null), $this->money($meta['factor'] ?? 1)),
            'invoice' => isset($meta['days_overdue'])
                ? sprintf('restantă de %d zile · %s din rest în fiecare din primele %d săpt.', (int) $meta['days_overdue'], $share ?? '1', (int) ($meta['weeks'] ?? 1))
                : sprintf('factura din %s, scadentă în săptămână', $this->dmy($meta['data_doc'] ?? null)),
            'manual' => sprintf('%s din sold în fiecare din primele %d săpt.', $share ?? '1', (int) ($meta['weeks'] ?? 1)),
            'opex' => $this->opexNote($meta),
            'new_receipts' => sprintf('încasări %s din dosare create în an anterior%s', $this->money($meta['ly_amount'] ?? 0).' '.$piece->currency, (float) ($meta['factor'] ?? 1) !== 1.0 ? ' × '.$this->money($meta['factor']) : ''),
            'new_costs' => sprintf('cost %s în an anterior%s', $this->money($meta['ly_amount'] ?? 0).' '.$piece->currency, (float) ($meta['factor'] ?? 1) !== 1.0 ? ' × '.$this->money($meta['factor']) : ''),
            'omc_payment' => trim(($piece->group ?? '').(! empty($meta['account']) && ($meta['rule'] ?? '') === 'account' ? ' · facturile merg pe '.$meta['account'] : '')),
            'etrip_receipts' => 'încasări emise în săptămână, pe segmentul dosarului',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function opexNote(array $meta): string
    {
        $origin = match ($meta['origin'] ?? null) {
            'parametri' => 'valoare lunară din parametri',
            'omc' => 'media lunară OMC '.($meta['window'] ?? ''),
            default => 'valoare implicită',
        };
        $accounts = collect((array) ($meta['accounts'] ?? []))
            ->sortDesc()
            ->take(6)
            ->map(fn ($monthly, $account) => $account.': '.$this->money($monthly))
            ->implode(', ');

        return $origin.($accounts !== '' ? ' · conturi '.$accounts : '');
    }

    /**
     * What a piece opens on: the invoice in the app, the supplier's
     * services in the check-in check, the partner in the app.
     *
     * @param  Collection<int, CashFlowDetail>  $pieces
     * @return array<int, array<string, mixed>>
     */
    private function links(Collection $pieces, CashFlowSnapshot $snapshot): array
    {
        $links = [];
        $invoices = $pieces->where('kind', 'invoice')->filter(fn (CashFlowDetail $piece) => isset($piece->meta['nr_doc']));

        if ($invoices->isNotEmpty()) {
            $company = $this->omc->company();
            $found = Invoice::query()
                ->when($company, fn ($q) => $q->where('company_id', $company->id))
                ->whereIn('nr_doc', $invoices->map(fn (CashFlowDetail $piece) => (string) $piece->meta['nr_doc'])->unique()->values())
                ->whereIn('tip_doc', $invoices->map(fn (CashFlowDetail $piece) => $piece->meta['tip_doc'])->unique()->values())
                ->with('department:id,name')
                ->get(['id', 'data_doc', 'tip_doc', 'nr_doc', 'department_id', 'approval_status'])
                ->keyBy(fn (Invoice $invoice) => $invoice->data_doc->toDateString().'|'.$invoice->tip_doc.'|'.rtrim($invoice->nr_doc));

            foreach ($invoices as $piece) {
                $invoice = $found->get($piece->meta['data_doc'].'|'.$piece->meta['tip_doc'].'|'.rtrim((string) $piece->meta['nr_doc']));

                if ($invoice) {
                    $links[$piece->id] = [
                        'link' => ['href' => route('invoices.show', $invoice->id), 'label' => 'factura'],
                        'status' => $invoice->approval_status,
                        'department' => $invoice->department?->name,
                    ];
                }
            }
        }

        $partners = $pieces->whereIn('kind', ['omc_payment', 'omc_receipt'])->pluck('meta.partner')->filter()->unique();

        if ($partners->isNotEmpty()) {
            $company = $this->omc->company();
            $ids = Partner::query()
                ->when($company, fn ($q) => $q->where('company_id', $company->id))
                ->whereIn('name', $partners->values())
                ->pluck('id', 'name');

            foreach ($pieces->whereIn('kind', ['omc_payment', 'omc_receipt']) as $piece) {
                $id = $ids[$piece->meta['partner'] ?? ''] ?? null;

                if ($id !== null) {
                    $links[$piece->id] = ['link' => ['href' => route('partners.show', $id), 'label' => 'partener']];
                }
            }
        }

        foreach ($pieces->where('kind', 'services') as $piece) {
            $meta = $piece->meta ?? [];

            if (! empty($meta['supplier']) && ! empty($meta['connection'])) {
                $category = match ($meta['category'] ?? null) {
                    'hotel' => 'hotel',
                    'transfer' => 'transfer',
                    default => 'all',
                };
                $links[$piece->id] = ['link' => ['href' => route('payment-checks.index', [
                    'connection' => $meta['connection'],
                    'supplier' => $meta['supplier'],
                    'from' => $meta['checkin_from'] ?? null,
                    'to' => $meta['checkin_to'] ?? null,
                    'category' => in_array($category, CheckinCostCheckService::CATEGORIES, true) ? $category : 'all',
                ]), 'label' => 'serviciile']];
            }
        }

        return $links;
    }

    /**
     * The documents behind an aggregated piece, read live on request.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, string>|null
     */
    private function expand(CashFlowDetail $piece, array $meta, CashFlowSnapshot $snapshot): ?array
    {
        return match ($piece->kind) {
            'omc_payment', 'omc_receipt' => [
                'kind' => 'omc',
                'direction' => $piece->kind === 'omc_payment' ? 'out' : 'in',
                'week' => $piece->week->toDateString(),
                'partner' => (string) ($meta['partner'] ?? ''),
                'coresp' => (string) ($meta['coresp'] ?? $piece->reference ?? ''),
                'until' => (string) ($snapshot->payload['today'] ?? ''),
            ],
            'etrip_receipts' => [
                'kind' => 'etrip_receipts',
                'week' => $piece->week->toDateString(),
                'connection' => (string) ($meta['connection'] ?? ''),
                'segment' => (string) ($meta['segment'] ?? ''),
                'until' => (string) ($snapshot->payload['today'] ?? ''),
            ],
            default => null,
        };
    }

    /**
     * How the line is worked out, with the parameters the snapshot used.
     *
     * @param  array<string, mixed>  $params
     */
    private function explanation(string $line, bool $actual, array $params): string
    {
        if ($actual) {
            return match (true) {
                in_array($line, ['B1', 'B2', 'B3', 'B4', 'B5', 'B6', 'B7'], true) => 'Încasările emise în eTrip în săptămână, pe segmentul dosarului pe care au fost alocate, în lei la cursul încasării.',
                $line === 'B10' => 'Încasările OMC (bancă + casă) de la contrapărțile contractelor charter.',
                $line === 'BX' => 'Tot ce a încasat OMC în săptămână, minus ce explică încasările eTrip pe dosare și cele din charter: încasări nealocate pe dosar, decalaje de înregistrare.',
                default => 'Plățile OMC (bancă + casă, fără transferurile între conturile proprii) din săptămână, puse pe linie după prima regulă care se aplică: contul corespondent, partenerul setat, contractul charter, restituirea către client, ce vinde furnizorul în eTrip, contul facturilor lui; restul pe CX.',
            };
        }

        $days = (int) ($params['payables']['days_before_checkin'] ?? 7);

        return match (true) {
            in_array($line, ['B1', 'B2', 'B3', 'B4', 'B5', 'B6', 'B7'], true) => sprintf('Scadențele de încasat ale dosarelor confirmate din eTrip: avansurile programate și soldul la data lui (sau cu %d zile înainte de plecare), din care s-a scăzut ce a plătit deja clientul, începând cu cele mai vechi.', (int) config('cashflow.etrip.receivables.fallback_days', 21)),
            $line === 'B8' => sprintf('%s%% din scadențele depășite de cel mult %d zile, egal pe primele %d săptămâni.', $params['overdue']['recent_pct'] ?? 0, (int) ($params['overdue']['recent_days'] ?? 60), (int) ($params['overdue']['recent_weeks'] ?? 4)),
            $line === 'B9' => sprintf('%s%% din scadențele depășite de peste %d zile, egal pe primele %d săptămâni.', $params['overdue']['old_pct'] ?? 0, (int) ($params['overdue']['recent_days'] ?? 60), (int) ($params['overdue']['recent_weeks'] ?? 4)),
            in_array($line, ['B10', 'C6', 'C7', 'C8', 'C9'], true) => 'Rotațiile, taxele și depozitele contractelor charter din aplicație, fiecare la data de plată a contractului lui, în lei la clauza de curs a contractului.',
            str_starts_with($line, 'B11') => sprintf('Scenariu: încasările din săptămâna corespunzătoare a anului anterior ale dosarelor create atunci, decalate 52 de săptămâni × %s.', $params['scenario']['factor'] ?? 1),
            in_array($line, ['C1', 'C2', 'C3', 'C5'], true) => sprintf('Costul de furnizor al serviciilor confirmate din eTrip (net de comision și de ce s-a plătit deja), plătit cu %d zile înainte de check-in; pe furnizor.', $days),
            $line === 'C4' => sprintf('Biletele de avion de linie comandate în ultimele %d zile, plătite la emitere, plus serviciile de zbor pe regula check-in-ului.', (int) ($params['payables']['ticket_days'] ?? 7)),
            $line === 'C10' => sprintf('Facturile furnizorilor deschise în OMC: cele restante, 1/%1$d din rest în fiecare din primele %1$d săptămâni; cele scadente mai târziu, integral în săptămâna scadenței.', max(1, (int) ($params['payables']['supplier_balance_weeks'] ?? 2))),
            $line === 'C11' => 'Scenariu: costul dosarelor create în aceeași perioadă a anului anterior, pe săptămâna de plată, decalat 52 de săptămâni.',
            in_array($line, ['C12', 'C13'], true) => 'Scenariu: programul charter al sezonului de bază decalat 364 de zile × factorul charter.',
            str_starts_with($line, 'D') => 'Costul lunar al categoriei (din parametri sau media OMC pe 12 luni), pus pe săptămâni după regula ei.',
            default => '',
        };
    }

    private function fraction(float $share): string
    {
        $weeks = (int) round(1 / max($share, 0.000001));

        return abs(1 / $weeks - $share) < 0.0001 ? '1/'.$weeks : $this->money($share * 100).'%';
    }

    private function money(mixed $value): string
    {
        $value = (float) $value;

        return number_format($value, abs($value) < 100 && floor($value) !== $value ? 2 : 0, ',', '.');
    }

    private function dmy(?string $date): string
    {
        return $date ? CarbonImmutable::parse($date)->format('d.m.Y') : '—';
    }
}
