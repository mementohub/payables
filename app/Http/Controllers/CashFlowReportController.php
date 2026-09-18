<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsRemote;
use App\Models\CashFlowSnapshot;
use App\Models\CharterContract;
use App\Models\CharterFlight;
use App\Services\CashFlow\BookingSegments;
use App\Services\CashFlow\CashFlowCellDetails;
use App\Services\CashFlow\CashFlowOverrides;
use App\Services\CashFlow\CashFlowParameters;
use App\Services\CashFlow\EtripCashFlowReader;
use App\Services\CashFlow\OmcCashFlowReader;
use App\Services\CashFlow\WeekGrid;
use App\Services\Maintenance\ArtisanRunner;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Rapoarte → WCFR 52 Weeks: the last cash-flow snapshot, its parameters and
 * the charter contracts it is built from. The snapshot itself is built in
 * the background (cashflow:build), never inside a request.
 */
class CashFlowReportController extends Controller
{
    use ReadsRemote;

    public function index(ArtisanRunner $runner, CashFlowParameters $parameters, CashFlowOverrides $overrides): Response
    {
        $params = $parameters->load();
        $computed = $this->computedOpex();

        return Inertia::render('reports/cash-flow', [
            // The payload is the whole 52-week report, so it is loaded after
            // the page rather than inside it: the page itself must stay small
            // however large a snapshot grows.
            'snapshot' => Inertia::defer(fn () => $this->snapshotPayload()),
            // Kept apart from the snapshot and laid over it on the page, so an
            // edit reloads a handful of rows instead of the whole report.
            'overrides' => $overrides->from(WeekGrid::fromToday()->start->toDateString()),
            'run' => $runner->status(ArtisanRunner::CASHFLOW),
            'lastRun' => Cache::get('cashflow:last_run'),
            'parameters' => $params,
            'opex' => CashFlowParameters::opexCatalogue($params, $computed),
            'connections' => collect((array) config('etrip.connections'))->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'contracts' => CharterContract::query()
                ->withCount('flights')
                ->withSum('flights', 'net_value')
                ->withSum('flights', 'taxes')
                ->withMin('flights', 'flight_date')
                ->withMax('flights', 'flight_date')
                ->orderByDesc('in_cash_flow')
                ->orderBy('season')
                ->orderBy('name')
                ->get()
                ->map(fn (CharterContract $contract) => [
                    'id' => $contract->id,
                    'name' => $contract->name,
                    'counterparty' => $contract->counterparty,
                    'buyer' => $contract->buyer,
                    'direction' => $contract->direction,
                    'in_cash_flow' => $contract->in_cash_flow,
                    'contract_no' => $contract->contract_no,
                    'signed_date' => $contract->signed_date?->toDateString(),
                    'period_from' => $contract->period_from?->toDateString(),
                    'period_to' => $contract->period_to?->toDateString(),
                    'season' => $contract->season,
                    'status' => $contract->status,
                    'operator' => $contract->operator,
                    'currency' => $contract->currency,
                    'days_before_flight' => $contract->days_before_flight,
                    'payment_basis' => $contract->payment_basis,
                    'taxes_rule' => $contract->taxes_rule,
                    'taxes_days' => $contract->taxes_days,
                    'taxes_month_day' => $contract->taxes_month_day,
                    'deposit_percent' => $contract->deposit_percent !== null ? (float) $contract->deposit_percent : null,
                    'deposit_amount' => $contract->deposit_amount !== null ? (float) $contract->deposit_amount : null,
                    'deposit_due_date' => $contract->deposit_due_date?->toDateString(),
                    'deposit_paid' => $contract->deposit_paid,
                    'deposit_settlement' => $contract->deposit_settlement,
                    'contract_value' => $contract->contract_value !== null ? (float) $contract->contract_value : null,
                    'contract_value_with_taxes' => $contract->contract_value_with_taxes !== null ? (float) $contract->contract_value_with_taxes : null,
                    'invoicing' => $contract->invoicing,
                    'fuel_rule' => $contract->fuel_rule,
                    'fx_markup_pct' => (float) $contract->fx_markup_pct,
                    'late_penalty_pct_per_day' => $contract->late_penalty_pct_per_day !== null ? (float) $contract->late_penalty_pct_per_day : null,
                    'cancellation_terms' => $contract->cancellation_terms,
                    'source' => $contract->source,
                    'confidence' => $contract->confidence,
                    'notes' => $contract->notes,
                    'flights_count' => (int) $contract->flights_count,
                    'flights_net' => (float) ($contract->flights_sum_net_value ?? 0),
                    'flights_taxes' => (float) ($contract->flights_sum_taxes ?? 0),
                    'first_flight' => $contract->flights_min_flight_date ? substr((string) $contract->flights_min_flight_date, 0, 10) : null,
                    'last_flight' => $contract->flights_max_flight_date ? substr((string) $contract->flights_max_flight_date, 0, 10) : null,
                ]),
            // A season is a few thousand rotations, so the programme is loaded
            // after the page rather than inside it, and streamed row by row.
            'flights' => Inertia::defer(fn () => $this->flightRows()),
            'schedule' => [
                'nightly' => sprintf('%02d:%02d', (int) config('cashflow.nightly_hour', 4), (int) config('cashflow.nightly_minute', 30)),
                'timezone' => (string) config('cashflow.timezone', 'Europe/Bucharest'),
                'weeks' => (int) config('cashflow.weeks', 52),
            ],
        ]);
    }

    /**
     * The monthly averages the last build worked out, for the parameters
     * form. Only that corner of the payload is read, so the page never
     * decodes the whole report to show a handful of numbers.
     *
     * @return array<string, float>
     */
    private function computedOpex(): array
    {
        $query = CashFlowSnapshot::query()->orderByDesc('built_at')->orderByDesc('id');
        $driver = $query->getConnection()->getDriverName();

        $opex = in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)
            ? json_decode((string) $query->selectRaw('json_extract(payload, ?) as opex', ['$.opex'])->value('opex'), true)
            : CashFlowSnapshot::latest()?->payload['opex'] ?? null;

        return collect(is_array($opex) ? $opex : [])
            ->pluck('computed', 'key')
            ->filter(fn ($value) => $value !== null)
            ->all();
    }

    /**
     * The last snapshot with its report payload.
     *
     * @return array<string, mixed>|null
     */
    private function snapshotPayload(): ?array
    {
        $snapshot = CashFlowSnapshot::latest();

        return $snapshot ? [
            'id' => $snapshot->id,
            'built_at' => $snapshot->built_at->toIso8601String(),
            'built_by' => $snapshot->built_by,
            'status' => $snapshot->status,
            'duration_ms' => $snapshot->duration_ms,
            'error' => $snapshot->error,
            'sources' => $snapshot->sources ?? [],
            'payload' => $snapshot->payload,
        ] : null;
    }

    /**
     * Every rotation with the dates its contract settles it on. The contracts
     * are read once and attached to each row, so the rules see the whole
     * contract without a query per rotation.
     *
     * @return list<array<string, mixed>>
     */
    private function flightRows(): array
    {
        $contracts = CharterContract::query()->get()->keyBy('id');
        $rows = [];

        CharterFlight::query()
            ->orderBy('flight_date')
            ->orderBy('id')
            ->lazy(500)
            ->each(function (CharterFlight $flight) use ($contracts, &$rows) {
                $contract = $contracts->get($flight->charter_contract_id);

                if ($contract === null) {
                    return;
                }

                $flight->setRelation('contract', $contract);

                $rows[] = [
                    'id' => $flight->id,
                    'charter_contract_id' => $flight->charter_contract_id,
                    'season' => $contract->season,
                    'operator' => $flight->operator,
                    'route' => $flight->route,
                    'flight_no' => $flight->flight_no,
                    'flight_date' => $flight->flight_date->toDateString(),
                    'seats' => $flight->seats,
                    'price_per_seat' => $flight->price_per_seat !== null ? (float) $flight->price_per_seat : null,
                    'net_value' => (float) $flight->net_value,
                    'taxes' => (float) $flight->taxes,
                    'pay_date' => $flight->pay_date?->toDateString(),
                    'taxes_pay_date' => $flight->taxes_pay_date?->toDateString(),
                    'payment_date' => $flight->paymentDate()->toDateString(),
                    // Null when the contract settles the taxes with the rotation.
                    'taxes_payment_date' => $flight->taxesPaymentDate()?->toDateString(),
                ];
            });

        return $rows;
    }

    /**
     * What one cell of a snapshot is made of: the pieces the build laid on
     * the line in those weeks (one week, or the weeks of a month).
     *
     * @return array<string, mixed>
     */
    public function drilldown(Request $request, CashFlowCellDetails $details): array
    {
        $validated = $request->validate([
            'snapshot' => ['required', 'integer', 'exists:cash_flow_snapshots,id'],
            'line' => ['required', 'string', 'max:16'],
            'weeks' => ['required', 'array', 'min:1', 'max:6'],
            'weeks.*' => ['required', 'date_format:Y-m-d'],
            'actual' => ['boolean'],
        ]);

        return $details->cell(
            CashFlowSnapshot::query()->findOrFail($validated['snapshot']),
            $validated['line'],
            array_values(array_unique($validated['weeks'])),
            (bool) ($validated['actual'] ?? false),
        );
    }

    /**
     * The documents behind an aggregated piece of a past week, read live:
     * a partner's bank and cash documents in OMC, or the eTrip receipts of
     * a booking segment.
     *
     * @return array{rows: list<array<string, mixed>>}
     */
    public function documents(Request $request, OmcCashFlowReader $omc, EtripCashFlowReader $etrip): array
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(['omc', 'etrip_receipts'])],
            'week' => ['required', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d'],
            'direction' => ['required_if:kind,omc', Rule::in(['in', 'out'])],
            'partner' => ['nullable', 'string', 'max:191'],
            'coresp' => ['nullable', 'string', 'max:40'],
            'connection' => ['required_if:kind,etrip_receipts', Rule::in(array_keys((array) config('etrip.connections')))],
            'segment' => ['required_if:kind,etrip_receipts', Rule::in(array_keys(BookingSegments::LABELS))],
        ]);

        $from = CarbonImmutable::parse($validated['week']);
        $to = $from->addWeek();

        if (! empty($validated['until']) && CarbonImmutable::parse($validated['until'])->lt($to)) {
            $to = CarbonImmutable::parse($validated['until']);
        }

        if ($validated['kind'] === 'omc') {
            $rows = $this->readingOmc(fn () => $omc->treasuryDocuments($from, $to, $validated['direction'], $validated['partner'] ?? null, (string) ($validated['coresp'] ?? '')));

            return ['rows' => $rows];
        }

        $rows = $this->readingEtrip(fn () => $etrip->receiptsIn($validated['connection'], $from, $to));

        return ['rows' => array_values(array_filter(
            $rows,
            fn (array $row) => BookingSegments::of(['segment_type' => $row['segment_type']]) === $validated['segment'],
        ))];
    }

    public function build(Request $request, ArtisanRunner $runner): RedirectResponse
    {
        if ($runner->isRunning(ArtisanRunner::CASHFLOW)) {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'Raportul se recalculează deja; pagina se actualizează singură când termină.']);

            return back();
        }

        try {
            $runner->start(ArtisanRunner::CASHFLOW, ['--by='.($request->user()?->name ?? 'manual')], $request->user()?->name);
        } catch (Throwable $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Recalcularea nu a putut fi pornită în fundal: '.trim($e->getMessage())]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Recalcularea a pornit în fundal; durează câteva minute, pagina se actualizează singură.']);

        return back();
    }

    public function parameters(Request $request, CashFlowParameters $parameters, ArtisanRunner $runner): RedirectResponse
    {
        $connections = array_keys((array) config('etrip.connections'));
        $opexKeys = array_column((array) config('cashflow.opex'), 'key');

        $validated = $request->validate([
            'etrip_connections' => ['required', 'array', 'min:1'],
            'etrip_connections.*' => ['string', Rule::in($connections)],
            'fx.mode' => ['required', Rule::in(['auto', 'manual'])],
            'fx.EUR' => ['required', 'numeric', 'gt:0'],
            'fx.USD' => ['required', 'numeric', 'gt:0'],
            'thresholds.minimum' => ['required', 'numeric', 'min:0'],
            'thresholds.comfort' => ['required', 'numeric', 'min:0'],
            'overdue.recent_days' => ['required', 'integer', 'between:1,365'],
            'overdue.recent_pct' => ['required', 'numeric', 'between:0,100'],
            'overdue.recent_weeks' => ['required', 'integer', 'between:1,52'],
            'overdue.old_pct' => ['required', 'numeric', 'between:0,100'],
            'payables.days_before_checkin' => ['required', 'integer', 'between:0,60'],
            'payables.prepaid_pct' => ['required', 'numeric', 'between:0,100'],
            'payables.ticket_days' => ['required', 'integer', 'between:0,60'],
            'payables.supplier_balance' => ['nullable', 'numeric', 'min:0'],
            'payables.supplier_balance_weeks' => ['required', 'integer', 'between:1,52'],
            'scenario.enabled' => ['required', 'boolean'],
            'scenario.factor' => ['required', 'numeric', 'between:0,5'],
            'scenario.charter_factor' => ['required', 'numeric', 'between:0,5'],
            'scenario.charter_base_season' => ['nullable', 'string', 'max:20'],
            'scenario.charter_target_season' => ['nullable', 'string', 'max:20'],
            'opex' => ['required', 'array'],
            'opex.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $validated['opex'] = array_map(fn ($v) => $v === null || $v === '' ? null : (float) $v, array_intersect_key($validated['opex'], array_flip($opexKeys)));
        $validated['payables']['supplier_balance'] = $validated['payables']['supplier_balance'] === null || $validated['payables']['supplier_balance'] === '' ? null : (float) $validated['payables']['supplier_balance'];
        $validated['scenario']['charter_base_season'] = $validated['scenario']['charter_base_season'] ?: null;
        $validated['scenario']['charter_target_season'] = $validated['scenario']['charter_target_season'] ?: null;

        $parameters->save($validated, $request->user());

        $message = 'Parametrii au fost salvați.';

        if (! $runner->isRunning(ArtisanRunner::CASHFLOW)) {
            try {
                $runner->start(ArtisanRunner::CASHFLOW, ['--by='.($request->user()?->name ?? 'manual')], $request->user()?->name);
                $message .= ' Raportul se recalculează în fundal.';
            } catch (Throwable $e) {
                $message .= ' Recalcularea nu a putut porni ('.trim($e->getMessage()).'); apăsați „Recalculează”.';
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
