<?php

namespace App\Http\Controllers;

use App\Models\BaseEstimate;
use App\Models\Company;
use App\Models\Department;
use App\Models\ForecastHistory;
use App\Models\Plan;
use App\Models\PlanLine;
use App\Models\PlanSection;
use App\Models\PlanVersion;
use App\Models\User;
use App\Services\Exports\PlanExport;
use App\Services\Exports\PlanPdfWriter;
use App\Services\Exports\PptxWriter;
use App\Services\Forecast\PlanBuilder;
use App\Services\Forecast\PlanEngine;
use App\Services\Forecast\PlanSections;
use App\Services\Reports\PnlReportService;
use App\Services\Xlsx\XlsxWorkbook;
use App\Support\ViewAs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Planul anului, făcut din pârghii, nu dintr-un procent.
 *
 * Fiecare categorie de produs se plănuiește pe pasageri, cu venitul și marja
 * lui; charterul se plănuiește pe locurile contractate și pe gradul de
 * ocupare, destinație cu destinație. Cheltuielile pornesc din ce s-a
 * cheltuit, linie cu linie. Orice cifră se poate scrie cu mâna, iar ce s-a
 * scris nu se mai calcă la următoarea pornire din istoric.
 */
class ForecastController extends Controller
{
    public function __construct(
        private PlanBuilder $builder,
        private PlanEngine $engine,
        private PnlReportService $pnl,
        private PlanSections $sections,
    ) {}

    public function index(Request $request): Response
    {
        $companies = Company::query()->orderBy('name')->get(['id', 'name']);
        $companyId = $request->integer('company_id') ?: $companies->first()?->id;

        $plans = Plan::query()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->with('createdBy:id,name')
            ->orderByDesc('year')->orderBy('name')
            ->get();

        $plan = $request->integer('plan')
            ? $plans->firstWhere('id', $request->integer('plan'))
            : $plans->first();

        // Aceleași comenzi ca pe P&L: luna și felul în care se citește —
        // cumulat de la începutul anului sau doar luna aia.
        $period = preg_match('/^(ytd|mtd)([1-9]|1[0-2])$/', (string) $request->string('period')) === 1
            ? (string) $request->string('period')
            : 'ytd12';
        $basis = in_array($request->string('basis')->toString(), PnlReportService::BASES, true)
            ? $request->string('basis')->toString()
            : PnlReportService::BASIS_RAS;
        // Operațional: venitul la data rezervării. Financiar: la data plecării.
        $mode = in_array($request->string('mode')->toString(), PnlReportService::MODES, true)
            ? $request->string('mode')->toString()
            : PnlReportService::MODE_OPERATIONAL;

        // Cât timp un administrator se uită prin ochii altuia, pagina se
        // desenează după drepturile aceluia: vede numai bucățile lui de
        // buget, fără tabloul întreg. Scrisul rămâne însă al contului lui —
        // `allow()` cântărește tot omul logat.
        $seer = ViewAs::effective($request);
        $boss = (bool) $seer?->canSeeReports();
        $mine = $plan && $seer ? $this->sections->mine($seer, $plan) : [];
        $output = $plan ? $this->engine->compute($plan, $period, $basis, $mode) : null;

        // Cine ține o bucată vede bucata, nu bugetul casei. Tăiem aici, nu
        // în pagină: altfel cifrele ar călători oricum la el, ascunse doar
        // de ochi.
        if ($output !== null && ! $boss) {
            $output = $this->onlyHis($output, $mine);
        }

        return Inertia::render('reports/forecast', [
            'companies' => $companies,
            'filters' => [
                'company_id' => $companyId,
                'plan' => $plan?->id,
                'period' => $period,
                'basis' => $basis,
                'mode' => $mode,
            ],
            'plans' => $plans->map(fn (Plan $one) => [
                'id' => $one->id,
                'year' => $one->year,
                'name' => $one->name,
                'base_year' => $one->base_year,
                'status' => $one->status,
                'created_by' => $one->createdBy?->name,
                'lines' => $one->lines()->count(),
            ])->values(),
            'plan' => $plan ? [
                'id' => $plan->id,
                'year' => $plan->year,
                'name' => $plan->name,
                'base_year' => $plan->base_year,
                'start' => $plan->start,
                'status' => $plan->status,
                'note' => $plan->note,
                'removed' => array_values((array) ($plan->removed ?? [])),
                'seeded_at' => $plan->seeded_at?->format('d.m.Y H:i'),
            ] : null,
            // Planul stă pe raportul P&L al anului de bază: cheltuielile,
            // linie cu linie, de acolo vin. Dacă raportul s-a refăcut după
            // ultima împrospătare — o corectură, o cheltuială mutată — se
            // spune pe față, ca omul să știe că are cifre vechi sub mână.
            'stale' => $plan ? $this->stale($plan) : null,
            'output' => $output,
            // Variantele salvate ale planului: la ele se întoarce omul când
            // vrea să vadă ce propunea acum două săptămâni.
            // Anul în curs, așa cum se va încheia: din el pornesc premisele
            // anului următor, nu din cele nouă luni trăite.
            'current' => $plan && $boss ? $this->engine->currentYear($plan, $basis, $mode) : null,
            // Bugetul se scrie în mai multe mâini: conducerea vede starea
            // tuturor bucăților, ceilalți numai a lor.
            'sections' => $plan ? array_values(array_filter(
                $this->sections->all($plan),
                fn (array $one) => $boss || in_array($one['key'], $mine, true),
            )) : [],
            'mine' => $mine,
            // Departamentele, pentru categoria nouă: cine o ține de-acum.
            'departments' => Department::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name')
                ->all(),
            'all_mine' => $boss,
            'versions' => $plan && $boss ? $plan->versions()->with('createdBy:id,name')->get()->map(fn (PlanVersion $version) => [
                'id' => $version->id,
                'label' => $version->label,
                'note' => $version->note,
                'created_by' => $version->createdBy?->name,
                'created_at' => $version->created_at?->format('d.m.Y H:i'),
                'period' => $version->period,
                'basis' => $version->basis,
                'mode' => $version->mode,
                'lines' => count($version->lines),
                'summary' => $version->summary,
            ])->values() : [],
            // Trei ani în urmă, pe fiecare rând: altfel gradul de ocupare și
            // banii pe pasager s-ar hotărî dintr-un singur an, care poate să
            // fi fost bun sau prost din întâmplare.
            'history' => $plan ? $this->history($plan, $basis) : [],
            'historyYears' => $plan ? $this->historyYears($plan) : [],
            'years' => range((int) now()->year + 1, (int) now()->year - 2),
            // Lunile pe care anul de bază nu le-a trăit încă și cifrele pe
            // care le-a scris omul pentru ele: din astea pornește planul.
            'openMonths' => $plan ? PlanBuilder::openMonths($plan->base_year) : [],
            'baseMonths' => $plan ? $this->builder->openCells($plan) : [],
        ]);
    }

    /**
     * Cheltuielile anilor dinainte, linie cu linie, din rapoartele lor.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function costHistory(Plan $plan, string $basis = PnlReportService::BASIS_RAS): array
    {
        $out = [];
        $rentLines = array_map('strval', (array) config('pnl.ifrs16.lines', []));

        foreach ($this->historyYears($plan) as $year) {
            $report = $this->pnl->cached($plan->company, $year);

            if ($report === null || isset($report['error'])) {
                continue;
            }

            // Pe baza IFRS 16, istoricul se citește ca în P&L: chiria
            // spațiilor nu mai e cheltuială de exploatare, iar amortizarea
            // și cheltuiala financiară poartă dreptul de utilizare și
            // dobânda din registrul de închirieri.
            $ifrs = $basis === PnlReportService::BASIS_IFRS16 ? ($report['leases']['months'] ?? []) : [];

            $buckets = [];

            foreach (($report['lines'] ?? []) as $line) {
                if ($ifrs !== [] && in_array((string) $line['saf'], $rentLines, true)) {
                    continue;
                }

                $buckets[PlanLine::OPEX.'|'.$line['saf']] = $line['months'] ?? [];
            }

            foreach (['amortizare', 'financiar', 'impozit'] as $bucket) {
                $months = $report['below'][$bucket]['months'] ?? [];

                foreach ($ifrs as $month => $cell) {
                    $add = match ($bucket) {
                        'amortizare' => (float) ($cell['depreciation'] ?? 0),
                        'financiar' => (float) ($cell['interest'] ?? 0) + (float) ($cell['fx'] ?? 0),
                        default => 0.0,
                    };

                    if (abs($add) < 0.005) {
                        continue;
                    }

                    $months[(int) $month]['total'] = (float) ($months[(int) $month]['total'] ?? 0) + $add;
                }

                $buckets[PlanLine::BELOW.'|'.$bucket] = $months;
            }

            foreach ($buckets as $key => $months) {
                $cell = ['volume' => 0.0, 'capacity' => 0.0, 'sold' => 0.0, 'net' => 0.0, 'margin' => 0.0, 'months' => []];

                foreach ($months as $month => $amount) {
                    $value = round((float) ($amount['total'] ?? 0), 2);

                    if (abs($value) < 0.005) {
                        continue;
                    }

                    $cell['months'][(int) $month] = ['volume' => 0.0, 'capacity' => 0.0, 'net' => $value, 'margin' => 0.0];
                    $cell['net'] = round($cell['net'] + $value, 2);
                }

                if ($cell['months'] !== []) {
                    $out[$key][$year] = $cell;
                }
            }
        }

        // Anul de bază se arată întregit, ca anii dinainte: altfel coloana
        // lui ar avea nouă luni lângă douăsprezece ale celorlalți, iar orice
        // creștere ar părea mai mare decât e.
        $overlay = $this->builder->openOverlay($plan);

        foreach ([PlanLine::OPEX => $overlay['lines'], PlanLine::BELOW => $overlay['below']] as $kind => $lines) {
            foreach ($lines as $saf => $months) {
                $cell = $out[$kind.'|'.$saf][$plan->base_year] ?? null;

                if ($cell === null) {
                    continue;
                }

                foreach ($months as $month => $amount) {
                    $cell['months'][(int) $month] = ['volume' => 0.0, 'capacity' => 0.0, 'net' => round((float) $amount, 2), 'margin' => 0.0];
                    $cell['net'] = round($cell['net'] + (float) $amount, 2);
                }

                $cell['estimated'] = count($months);
                $out[$kind.'|'.$saf][$plan->base_year] = $cell;
            }
        }

        return $out;
    }

    /**
     * Cum a mers fiecare rând în anii dinainte.
     *
     * @return array<string, array<int, array<string, float>>>
     */
    private function history(Plan $plan, string $basis = PnlReportService::BASIS_RAS): array
    {
        $rows = ForecastHistory::query()
            ->where('company_id', $plan->company_id)
            ->whereIn('year', $this->historyYears($plan))
            ->get();

        // Cheltuielile n-au istoric strâns în `forecast_history`: ele stau în
        // rapoartele construite ale anilor dinainte, linie cu linie. Fără
        // ele, coloanele 2024–2026 de pe fila „Cheltuieli” ar fi goale, iar
        // omul n-ar avea pe ce judeca o creștere.
        $out = $this->costHistory($plan, $basis);

        // Amestecul de canale al fiecărui an: din el iese cât s-a vândut
        // atunci prin agenții partenere. Fără reperul ăsta, omul scrie
        // partea de B2B a anului viitor fără să vadă de unde vine.
        $mix = [];

        foreach ($rows->where('kind', 'mix') as $row) {
            $where = &$mix[(string) $row->key][(int) $row->year][(int) $row->month];
            $where['all'] = ($where['all'] ?? 0) + (float) $row->net;
            $where['b2b'] = ($where['b2b'] ?? 0) + (in_array((string) $row->channel, (array) config('pnl.segments.b2b.channels', ['b2b']), true) ? (float) $row->net : 0);
            unset($where);
        }

        foreach ($rows->where('kind', '!=', 'mix') as $row) {
            $key = $row->kind.'|'.$row->key;
            $cell = $out[$key][$row->year] ?? [
                'volume' => 0.0, 'capacity' => 0.0, 'sold' => 0.0, 'net' => 0.0, 'margin' => 0.0, 'months' => [],
            ];

            $month = (int) $row->month;
            $before = $cell['months'][$month] ?? ['volume' => 0.0, 'capacity' => 0.0, 'net' => 0.0, 'margin' => 0.0];

            // Și pe luni, nu doar pe an: cardul fiecărei luni arată cu ce a
            // venit anul de bază, ca omul să vadă de la ce pornește.
            $cell['months'][$month] = [
                'volume' => round($before['volume'] + $row->volume, 2),
                'capacity' => round($before['capacity'] + (float) $row->capacity, 2),
                'net' => round($before['net'] + $row->net, 2),
                'margin' => round($before['margin'] + $row->margin, 2),
            ];

            $out[$key][$row->year] = [
                ...$cell,
                'volume' => round($cell['volume'] + $row->volume, 2),
                'capacity' => round($cell['capacity'] + (float) $row->capacity, 2),
                'sold' => round($cell['sold'] + (float) $row->sold, 2),
                'net' => round($cell['net'] + $row->net, 2),
                'margin' => round($cell['margin'] + $row->margin, 2),
            ];
        }

        return $this->withB2b($out, $mix);
    }

    /**
     * Cât s-a vândut prin agenții partenere, pe fiecare lună din istoric.
     *
     * Amestecul se ține pe categorie de produs; o cursă de charter îl ia pe
     * al categoriei ei, fiindcă un loc de avion se vinde pe aceleași
     * drumuri ca restul produsului.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $out
     * @param  array<string, array<int, array<int, array<string, float>>>>  $mix
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function withB2b(array $out, array $mix): array
    {
        foreach ($out as $key => $years) {
            [$kind, $name] = array_pad(explode('|', (string) $key, 2), 2, '');

            if (! in_array($kind, [PlanLine::CATEGORY, PlanLine::CHARTER], true)) {
                continue;
            }

            $theirs = $mix[$kind === PlanLine::CHARTER ? PlanBuilder::CHARTER_CATEGORY : $name] ?? null;

            if ($theirs === null) {
                continue;
            }

            foreach ($years as $year => $cell) {
                $all = 0.0;
                $b2b = 0.0;

                foreach (($theirs[$year] ?? []) as $month => $share) {
                    $all += $share['all'] ?? 0;
                    $b2b += $share['b2b'] ?? 0;

                    if (isset($cell['months'][$month]) && ($share['all'] ?? 0) > 0) {
                        $out[$key][$year]['months'][$month]['b2b'] = round($share['b2b'] / $share['all'], 4);
                    }
                }

                if ($all > 0) {
                    $out[$key][$year]['b2b'] = round($b2b / $all, 4);
                }
            }
        }

        return $out;
    }

    /**
     * S-a refăcut raportul P&L după ultima împrospătare a planului?
     *
     * @return array<string, string>|null
     */
    private function stale(Plan $plan): ?array
    {
        $report = $this->pnl->cached($plan->company, $plan->base_year);
        $built = $report['meta']['generated_at'] ?? null;

        if ($built === null) {
            return null;
        }

        $at = Carbon::parse((string) $built);

        if ($plan->seeded_at !== null && $at->lte($plan->seeded_at)) {
            return null;
        }

        return [
            'report' => $at->format('d.m.Y H:i'),
            'plan' => $plan->seeded_at?->format('d.m.Y H:i') ?? 'niciodată',
        ];
    }

    /**
     * Anii de istoric arătați: cel de bază și cei doi dinaintea lui, fără
     * anul plănuit — pe el îl scriem noi.
     *
     * @return list<int>
     */
    private function historyYears(Plan $plan): array
    {
        $last = min((int) $plan->base_year, (int) $plan->year - 1);

        // De la cel mai vechi la cel mai nou: anii se citesc de la stânga la
        // dreapta, iar planul vine după ei, ca peste tot în pagină.
        return [$last - 2, $last - 1, $last];
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'year' => ['required', 'integer', 'between:2024,2100'],
            'base_year' => ['required', 'integer', 'between:2020,2100'],
            'name' => ['required', 'string', 'max:80'],
        ]);

        $this->boss($request);

        $plan = Plan::query()->create([
            ...$validated,
            'status' => Plan::DRAFT,
            'created_by_id' => $request->user()?->id,
        ]);

        $written = $this->builder->seed($plan);

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf(
            'Planul „%s” a pornit din %d: %d pârghii scrise. Schimbă ce nu-ți convine.',
            $plan->name, $plan->base_year, $written,
        )]);

        return redirect()->route('reports.forecast.index', ['company_id' => $plan->company_id, 'plan' => $plan->id]);
    }

    /**
     * De unde pornește planul: din trendul ultimilor ani sau din anul de
     * bază ca atare.
     *
     * Întrebarea „dacă nu schimb nimic, bugetul e anul ăsta încă o dată?”
     * are acum un răspuns pe care îl alege omul, nu unul pe care îl
     * presupunem noi.
     */
    public function updateStart(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'start' => ['required', Rule::in([PlanBuilder::START_TREND, PlanBuilder::START_BASE])],
        ]);

        $this->boss($request);

        $plan->update($validated);
        $written = $this->builder->seed($plan);

        Inertia::flash('toast', ['type' => 'success', 'message' => $validated['start'] === PlanBuilder::START_BASE
            ? sprintf('Planul pornește acum din %d ca atare, fără creștere: %d pârghii rescrise.', $plan->base_year, $written)
            : sprintf('Planul pornește acum din %d, dus mai departe cu trendul ultimilor ani: %d pârghii rescrise.', $plan->base_year, $written)]);

        return back();
    }

    /** Pornește din nou din istoric, fără să calce peste ce s-a scris de mână. */
    public function reseed(Request $request, Plan $plan): RedirectResponse
    {
        $this->boss($request);

        $written = $this->builder->seed($plan, keepManual: ! $request->boolean('wipe'));

        Inertia::flash('toast', ['type' => 'success', 'message' => $request->boolean('wipe')
            ? sprintf('Planul a fost refăcut din %d, de la zero: %d pârghii.', $plan->base_year, $written)
            : sprintf('Planul a fost împrospătat din %d: %d pârghii. Ce era scris de mână a rămas.', $plan->base_year, $written)]);

        return back();
    }

    /** O celulă: pasageri, locuri, grad de ocupare, bani pe pasager sau sumă. */
    public function updateLine(Request $request, PlanLine $line): RedirectResponse
    {
        $validated = $request->validate([
            'volume' => ['nullable', 'numeric', 'min:0'],
            'capacity' => ['nullable', 'numeric', 'min:0'],
            'load' => ['nullable', 'numeric', 'between:0,1.5'],
            'unit_net' => ['nullable', 'numeric'],
            'unit_margin' => ['nullable', 'numeric'],
            'amount' => ['nullable', 'numeric'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->allow($request, $line->plan, $line->kind, $line->key, $line->label);

        $line->update([...array_filter($validated, fn ($value) => $value !== null), 'manual' => true]);

        return back();
    }

    /**
     * Un rând întreg, pe toate lunile: fie o valoare pusă peste tot, fie o
     * creștere în procente față de ce e acum.
     */
    public function updateRow(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in([PlanLine::CATEGORY, PlanLine::CHARTER, PlanLine::OPEX, PlanLine::BELOW, PlanLine::RETAIL])],
            'key' => ['required', 'string', 'max:255'],
            'field' => ['required', Rule::in(['volume', 'capacity', 'load', 'b2b', 'driver_rate', 'unit_net', 'unit_margin', 'unit_cost', 'rent', 'amount'])],
            'value' => ['nullable', 'numeric'],
            'growth' => ['nullable', 'numeric', 'between:-100,500'],
        ]);

        $lines = $plan->lines()->where('kind', $validated['kind'])->where('key', $validated['key'])->get();

        $this->allow($request, $plan, $validated['kind'], $validated['key'], $lines->first()?->label);

        foreach ($lines as $line) {
            $value = ($validated['value'] ?? null) !== null
                ? (float) $validated['value']
                : round((float) $line->{$validated['field']} * (1 + (float) ($validated['growth'] ?? 0) / 100), 4);

            $line->update([$validated['field'] => $value, 'manual' => true]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf('%d luni schimbate pe „%s”.', $lines->count(), $validated['key'])]);

        return back();
    }

    /**
     * Toate schimbările deodată.
     *
     * Omul reglează zeci de celule până iese planul cum vrea; dacă fiecare
     * cifră ar fi o cerere la server, ar aștepta de zeci de ori și ar fi
     * aruncat înapoi în tabelul de sus la fiecare apăsare. Așa, scrie liniștit
     * și salvează o dată.
     */
    public function bulk(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'cells' => ['array'],
            'cells.*.id' => ['required', 'integer'],
            'cells.*.field' => ['required', Rule::in(['volume', 'capacity', 'load', 'b2b', 'driver_rate', 'unit_net', 'unit_margin', 'unit_cost', 'rent', 'amount'])],
            'cells.*.value' => ['required', 'numeric'],
            'rows' => ['array'],
            'rows.*.kind' => ['required', Rule::in([PlanLine::CATEGORY, PlanLine::CHARTER, PlanLine::OPEX, PlanLine::BELOW, PlanLine::RETAIL])],
            'rows.*.key' => ['required', 'string', 'max:255'],
            'rows.*.field' => ['required', Rule::in(['volume', 'capacity', 'load', 'b2b', 'driver_rate', 'unit_net', 'unit_margin', 'unit_cost', 'rent', 'amount'])],
            'rows.*.value' => ['nullable', 'numeric'],
            'rows.*.growth' => ['nullable', 'numeric', 'between:-100,500'],
        ]);

        $cells = 0;
        $rows = 0;

        foreach (($validated['rows'] ?? []) as $row) {
            $this->allow($request, $plan, $row['kind'], $row['key']);
        }

        foreach (($validated['cells'] ?? []) as $cell) {
            $line = $plan->lines()->whereKey($cell['id'])->first();

            if ($line !== null) {
                $this->allow($request, $plan, $line->kind, $line->key, $line->label);
            }
        }

        DB::transaction(function () use ($plan, $validated, &$cells, &$rows) {
            foreach (($validated['rows'] ?? []) as $row) {
                $lines = $plan->lines()->where('kind', $row['kind'])->where('key', $row['key'])->get();

                foreach ($lines as $line) {
                    $value = ($row['value'] ?? null) !== null
                        ? (float) $row['value']
                        : round((float) $line->{$row['field']} * (1 + (float) ($row['growth'] ?? 0) / 100), 4);

                    $line->update([$row['field'] => $value, 'manual' => true]);
                }

                $rows++;
            }

            // Celulele se scriu după rânduri: ce s-a reglat pe o lună anume
            // bate ce s-a pus pe tot anul.
            foreach (($validated['cells'] ?? []) as $cell) {
                $line = $plan->lines()->whereKey($cell['id'])->first();

                if ($line === null) {
                    continue;
                }

                $line->update([$cell['field'] => (float) $cell['value'], 'manual' => true]);
                $cells++;
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf(
            'Salvat: %d %s și %d %s.',
            $cells, $cells === 1 ? 'lună' : 'luni',
            $rows, $rows === 1 ? 'rând întreg' : 'rânduri întregi',
        )]);

        return back();
    }

    /** Înapoi la cifra din istoric: rândul se recitește la următoarea pornire. */
    public function resetRow(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'string', 'max:12'],
            'key' => ['required', 'string', 'max:255'],
        ]);

        $this->allow($request, $plan, $validated['kind'], $validated['key']);

        $plan->lines()->where('kind', $validated['kind'])->where('key', $validated['key'])->update(['manual' => false]);
        $this->builder->seed($plan);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rândul s-a întors la cifrele din istoric.']);

        return back();
    }

    /**
     * Îngheață planul de acum într-o variantă.
     *
     * Se păstrează pârghiile întregi, nu doar totalurile: altfel varianta ar
     * fi o poză de care nu te poți folosi. Cu ele se poate reîncărca planul
     * oricând, sau se poate scoate pe hârtie cu tot cu ipoteze.
     */
    public function storeVersion(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
            'period' => ['nullable', 'string', 'max:8'],
            'basis' => ['nullable', Rule::in(PnlReportService::BASES)],
            'mode' => ['nullable', Rule::in(PnlReportService::MODES)],
        ]);

        $this->boss($request);

        $period = preg_match('/^(ytd|mtd)([1-9]|1[0-2])$/', (string) ($validated['period'] ?? '')) === 1
            ? (string) $validated['period']
            : 'ytd12';
        $basis = $validated['basis'] ?? PnlReportService::BASIS_RAS;
        $mode = $validated['mode'] ?? PnlReportService::MODE_OPERATIONAL;

        $output = $this->engine->compute($plan, $period, $basis, $mode);

        $version = PlanVersion::query()->create([
            'plan_id' => $plan->id,
            'label' => $validated['label'],
            'note' => $validated['note'] ?? null,
            'created_by_id' => $request->user()?->id,
            'period' => $period,
            'basis' => $basis,
            'mode' => $mode,
            'lines' => $this->snapshot($plan),
            'summary' => [
                'net' => $output['totals']['net'],
                'margin' => $output['totals']['margin'],
                'opex' => $output['totals']['opex'],
                'ebitda' => $output['totals']['ebitda'],
                'net_profit' => $output['totals']['net_profit'],
                'pax' => $output['totals']['pax'],
                'services' => $output['totals']['services'],
                'seats' => $output['totals']['seats'],
                'base' => $output['base'],
            ],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf(
            'Varianta „%s” a fost salvată: %d pârghii, venit %s lei.',
            $version->label, count($version->lines), number_format((float) $output['totals']['net'], 0, ',', '.'),
        )]);

        return back();
    }

    /**
     * Pune planul înapoi pe cifrele unei variante.
     *
     * Ce e acum se pierde dacă nu e salvat la rândul lui, așa că pagina
     * întreabă înainte. Rândurile se scriu toate deodată: un plan pe jumătate
     * încărcat ar fi mai rău decât niciunul.
     */
    public function restoreVersion(Request $request, Plan $plan, PlanVersion $version): RedirectResponse
    {
        $this->boss($request);

        abort_unless($version->plan_id === $plan->id, 404);

        DB::transaction(function () use ($plan, $version) {
            $plan->lines()->delete();

            foreach (array_chunk($version->lines, 500) as $chunk) {
                $plan->lines()->createMany($chunk);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf(
            'Planul a fost încărcat din varianta „%s” (%s).',
            $version->label, $version->created_at?->format('d.m.Y H:i') ?? '',
        )]);

        return back();
    }

    public function destroyVersion(Request $request, Plan $plan, PlanVersion $version): RedirectResponse
    {
        $this->boss($request);

        abort_unless($version->plan_id === $plan->id, 404);

        $version->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Varianta a fost ștearsă.']);

        return back();
    }

    /**
     * Pârghiile planului, în forma în care se pot scrie înapoi.
     *
     * @return list<array<string, mixed>>
     */
    private function snapshot(Plan $plan): array
    {
        return $plan->lines()
            ->orderBy('kind')->orderBy('key')->orderBy('month')
            ->get()
            ->map(fn (PlanLine $line) => [
                'kind' => $line->kind,
                'unit' => $line->unit,
                'key' => $line->key,
                'label' => $line->label,
                'month' => (int) $line->month,
                'volume' => $line->volume,
                'capacity' => $line->capacity,
                'load' => $line->load,
                'unit_net' => $line->unit_net,
                'unit_margin' => $line->unit_margin,
                'unit_cost' => $line->unit_cost,
                'amount' => $line->amount,
                'manual' => (bool) $line->manual,
                'note' => $line->note,
            ])
            ->all();
    }

    /**
     * Ține mâna celui care n-are voie să scrie aici.
     *
     * Conducerea umblă oriunde; restul, numai în bucata departamentului lor
     * și numai cât timp n-au trimis-o spre consolidare.
     */
    private function allow(Request $request, Plan $plan, string $kind, string $key, ?string $label = null): void
    {
        $user = $request->user();
        $section = $this->sections->forLine($kind, $key, $label);

        abort_unless(
            $user !== null && $this->sections->editable($user, $plan, $section),
            403,
            sprintf('„%s” e ținută de alt departament sau a fost deja trimisă spre consolidare.', $this->sections->label($section)),
        );
    }

    /**
     * Planul întreg e al conducerii.
     *
     * Un șef de departament scrie în bucata lui — pârghiile ei, rândurile
     * ei — dar planul ca întreg (pornirea, împrospătarea, variantele,
     * exportul, ștergerea) rămâne al celor care văd tot. Altfel ar lua
     * acasă, într-un Excel, bugetul la care n-are drept.
     */
    private function boss(Request $request): void
    {
        abort_unless(
            (bool) $request->user()?->canSeeReports(),
            403,
            'Planul întreg e al conducerii; dumneavoastră scrieți bucata dumneavoastră.',
        );
    }

    /**
     * Trimite o bucată de buget spre consolidare. După asta se încuie.
     */
    public function submitSection(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();

        abort_unless(
            $user !== null && $this->sections->editable($user, $plan, $validated['key']),
            403,
            'Bucata asta nu e a dumneavoastră sau e deja trimisă.',
        );

        PlanSection::query()->updateOrCreate(
            ['plan_id' => $plan->id, 'key' => $validated['key']],
            [
                'status' => PlanSection::SUBMITTED,
                'submitted_by_id' => $user->id,
                'submitted_at' => now(),
                'accepted_by_id' => null,
                'accepted_at' => null,
                'note' => $validated['note'] ?? null,
            ],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf(
            '„%s” a plecat spre consolidare. Până se dă înapoi, nu se mai scrie în ea.',
            $this->sections->label($validated['key']),
        )]);

        return back();
    }

    /**
     * Conducerea primește o bucată, sau o dă înapoi cu un cuvânt de ce.
     */
    public function reviewSection(Request $request, Plan $plan): RedirectResponse
    {
        abort_unless((bool) $request->user()?->canSeeReports(), 403);

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:120'],
            'status' => ['required', Rule::in([PlanSection::ACCEPTED, PlanSection::DRAFT])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $accepted = $validated['status'] === PlanSection::ACCEPTED;

        PlanSection::query()->updateOrCreate(
            ['plan_id' => $plan->id, 'key' => $validated['key']],
            [
                'status' => $validated['status'],
                'accepted_by_id' => $accepted ? $request->user()?->id : null,
                'accepted_at' => $accepted ? now() : null,
                'note' => $validated['note'] ?? null,
            ],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf(
            $accepted ? '„%s” a fost primită.' : '„%s” s-a întors la departament.',
            $this->sections->label($validated['key']),
        )]);

        return back();
    }

    /**
     * Un rând nou în plan: o cursă pe care o zburăm abia anul viitor, o
     * categorie pe care o deschidem.
     *
     * Rândul se scrie de mână, deci pornirea din istoric nu-l mai atinge.
     */
    public function storeRow(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in([PlanLine::CATEGORY, PlanLine::CHARTER, PlanLine::OPEX, PlanLine::BELOW, PlanLine::RETAIL])],
            'key' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', Rule::in([PlanLine::PAX, PlanLine::SERVICE])],
            'months' => ['required', 'array', 'min:1'],
            'months.*' => ['integer', 'between:1,12'],
            'volume' => ['nullable', 'numeric', 'min:0'],
            'capacity' => ['nullable', 'numeric', 'min:0'],
            'load' => ['nullable', 'numeric', 'between:0,1.5'],
            // Cât se vinde prin agenții partenere, ca parte din unu.
            'b2b' => ['nullable', 'numeric', 'between:0,1'],
            'unit_net' => ['nullable', 'numeric'],
            'unit_margin' => ['nullable', 'numeric'],
            'unit_cost' => ['nullable', 'numeric'],
            'rent' => ['nullable', 'numeric'],
            'amount' => ['nullable', 'numeric'],
            'note' => ['nullable', 'string', 'max:500'],
            // O categorie nouă își alege stăpânul: cea venită din istoric îl
            // știe din configurație, asta n-are de unde.
            'department' => ['nullable', 'string', 'max:255'],
        ]);

        $key = trim($validated['key']);

        $this->allow($request, $plan, $validated['kind'], $key, $validated['label'] ?? null);

        // Lunile care există deja nu se scriu a doua oară: cererea poate fi
        // și „mai adaugă-i noiembrie”, pe un rând care are deja restul
        // anului — o cursă pe care n-am zburat-o niciodată în noiembrie, dar
        // pe care vrem s-o zburăm la anul.
        $taken = $plan->lines()
            ->where('kind', $validated['kind'])
            ->where('key', $key)
            ->pluck('month')
            ->map(fn ($month) => (int) $month)
            ->all();

        $validated['months'] = array_values(array_diff(
            array_map('intval', $validated['months']),
            $taken,
        ));

        abort_if($validated['months'] === [], 422, 'Lunile cerute sunt deja în plan.');

        DB::transaction(function () use ($plan, $validated, $key) {
            // Dacă fusese scos cândva, acum se întoarce.
            $plan->update([
                'removed' => array_values(array_diff(
                    (array) ($plan->removed ?? []),
                    [$validated['kind'].'|'.$key],
                )),
            ]);

            foreach ($validated['months'] as $month) {
                $plan->lines()->create([
                    'kind' => $validated['kind'],
                    'unit' => $validated['unit'] ?? PlanLine::PAX,
                    'key' => $key,
                    'label' => ($validated['label'] ?? null) ?: $key,
                    'month' => (int) $month,
                    'volume' => $validated['volume'] ?? null,
                    'capacity' => $validated['capacity'] ?? null,
                    'load' => $validated['load'] ?? null,
                    'b2b' => $validated['b2b'] ?? null,
                    'unit_net' => $validated['unit_net'] ?? null,
                    'unit_margin' => $validated['unit_margin'] ?? null,
                    'unit_cost' => $validated['unit_cost'] ?? null,
                    'rent' => $validated['rent'] ?? null,
                    'amount' => $validated['amount'] ?? null,
                    'manual' => true,
                    'note' => $validated['note'] ?? 'Rând adăugat cu mâna, în afara istoricului.',
                ]);
            }
        });

        // Stăpânul categoriei noi, scris în plan. Numai conducerea îl pune:
        // ea deschide categorii, ceilalți scriu în ale lor.
        $owner = trim((string) ($validated['department'] ?? ''));

        if ($owner !== '' && $validated['kind'] === PlanLine::CATEGORY && $request->user()?->hasRole(User::ROLE_TOP_MANAGEMENT)) {
            PlanSection::query()->updateOrCreate(
                ['plan_id' => $plan->id, 'key' => $this->sections->forLine(PlanLine::CATEGORY, $key)],
                ['department' => $owner],
            );
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf(
            '„%s” a intrat în plan, pe %d %s.',
            $key, count($validated['months']), count($validated['months']) === 1 ? 'lună' : 'luni',
        )]);

        return back();
    }

    /**
     * Scoate un rând din plan — cu tot cu lunile lui.
     *
     * Nu se șterg doar liniile: pornirea din istoric le-ar aduce înapoi la
     * prima împrospătare. Planul ține minte că rândul nu se mai vrea, și tot
     * de acolo se poate pune la loc.
     */
    public function destroyRow(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in([PlanLine::CATEGORY, PlanLine::CHARTER, PlanLine::OPEX, PlanLine::BELOW, PlanLine::RETAIL])],
            'key' => ['nullable', 'string', 'max:255'],
            // Mai multe deodată: zece curse pe care nu le mai zburăm se
            // scot dintr-o mișcare, nu una câte una.
            'keys' => ['nullable', 'array'],
            'keys.*' => ['string', 'max:255'],
        ]);

        $keys = array_values(array_unique(array_filter([
            ...(array) ($validated['keys'] ?? []),
            $validated['key'] ?? null,
        ])));

        abort_if($keys === [], 422, 'Nu s-a spus ce rând iese din plan.');

        foreach ($keys as $key) {
            $this->allow($request, $plan, $validated['kind'], $key);
        }

        DB::transaction(function () use ($plan, $validated, $keys) {
            $plan->lines()->where('kind', $validated['kind'])->whereIn('key', $keys)->delete();

            $plan->update([
                'removed' => array_values(array_unique([
                    ...(array) ($plan->removed ?? []),
                    ...array_map(fn (string $key) => $validated['kind'].'|'.$key, $keys),
                ])),
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => count($keys) === 1
            ? sprintf('„%s” a ieșit din plan. Se poate pune înapoi de sub tabel.', $keys[0])
            : sprintf('%d rânduri au ieșit din plan. Se pot pune înapoi de sub tabel.', count($keys))]);

        return back();
    }

    /** Pune înapoi un rând scos, cu cifrele lui din istoric. */
    public function restoreRow(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in([PlanLine::CATEGORY, PlanLine::CHARTER, PlanLine::OPEX, PlanLine::BELOW, PlanLine::RETAIL])],
            'key' => ['required', 'string', 'max:255'],
        ]);

        $this->allow($request, $plan, $validated['kind'], $validated['key']);

        $plan->update([
            'removed' => array_values(array_diff(
                (array) ($plan->removed ?? []),
                [$validated['kind'].'|'.$validated['key']],
            )),
        ]);

        $this->builder->seed($plan);

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf(
            '„%s” s-a întors în plan, cu cifrele din istoric.', $validated['key'],
        )]);

        return back();
    }

    /**
     * Planul, tăiat pe cât vede omul.
     *
     * Un șef de departament își scrie bucata lui: rândurile ei le vede, dar
     * raportul întreg al casei — venitul, marja, impozitul, variantele — nu
     * sunt ale lui. Rândurile celorlalți nici nu pleacă din server.
     *
     * @param  array<string, mixed>  $output
     * @param  list<string>  $mine
     * @return array<string, mixed>
     */
    private function onlyHis(array $output, array $mine): array
    {
        $his = fn (array $rows) => array_values(array_filter(
            $rows,
            fn (array $row) => in_array((string) ($row['section'] ?? ''), $mine, true),
        ));

        $zero = fn (array $numbers) => array_map(fn () => 0, $numbers);

        return [
            ...$output,
            'categories' => $his((array) $output['categories']),
            'charter' => $his((array) $output['charter']),
            'opex' => $his((array) $output['opex']),
            'below' => $his((array) $output['below']),
            'retail' => $his((array) $output['retail']),
            // Tabloul casei rămâne la casă.
            'statement' => [],
            'columns' => ['product' => [], 'channel' => []],
            'channels' => [],
            'months' => (object) [],
            'totals' => $zero((array) $output['totals']),
            'risk' => $zero((array) $output['risk']),
            'years' => [],
            'leases' => null,
            'base' => [
                'year' => $output['base']['year'] ?? null,
                'built' => $output['base']['built'] ?? false,
                'estimated' => $output['base']['estimated'] ?? [],
            ],
        ];
    }

    /**
     * Scoate o categorie de produs întreagă din plan.
     *
     * Nu orice bucată: rețeaua de agenții și cheltuielile rămân acolo
     * oricum, fiindcă fără ele planul n-ar mai fi un plan. O categorie de
     * produs, însă, se poate închide — nu mai vindem Charter Bus la anul —
     * și atunci ies toate rândurile ei, cu tot cu sub-linii. Planul ține
     * minte, ca la orice rând scos, ca să nu le aducă împrospătarea înapoi.
     */
    public function destroySection(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:120'],
        ]);

        $key = $validated['key'];

        abort_unless(
            str_starts_with($key, 'category:'),
            422,
            'Numai categoriile de produs ies din plan; rețeaua de agenții și cheltuielile rămân.',
        );

        $user = $request->user();

        abort_unless(
            $user !== null && $this->sections->editable($user, $plan, $key),
            403,
            sprintf('„%s” e ținută de alt departament sau a fost deja trimisă spre consolidare.', $this->sections->label($key)),
        );

        $lines = $plan->lines()->get()
            ->filter(fn (PlanLine $line) => $this->sections->forLine($line->kind, $line->key, $line->label) === $key);

        abort_if($lines->isEmpty(), 422, 'Categoria nu mai are niciun rând în plan.');

        $gone = $lines->map(fn (PlanLine $line) => $line->kind.'|'.$line->key)->unique()->values()->all();

        DB::transaction(function () use ($plan, $lines, $gone, $key) {
            $plan->lines()->whereIn('id', $lines->pluck('id')->all())->delete();

            $plan->update([
                'removed' => array_values(array_unique([
                    ...(array) ($plan->removed ?? []),
                    ...$gone,
                ])),
            ]);

            PlanSection::query()->where('plan_id', $plan->id)->where('key', $key)->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf(
            '„%s” a ieșit din plan, cu tot cu %d %s. Se poate pune înapoi de sub tabel.',
            $this->sections->label($key),
            count($gone),
            count($gone) === 1 ? 'rând' : 'rânduri',
        )]);

        return back();
    }

    /**
     * O lună din anul de bază pe care n-am trăit-o încă, scrisă de mână.
     *
     * Planul lui 2027 pornește din 2026, dar 2026 are, în octombrie, nouă
     * luni adevărate și trei care vin. Până acum ultimele trei se ghiceau
     * singure; acum și le scrie omul, iar planul se reașază sub ele.
     */
    public function updateBase(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in([PlanLine::CATEGORY, PlanLine::CHARTER, PlanLine::OPEX, PlanLine::BELOW, PlanLine::RETAIL])],
            'key' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', Rule::in([PlanLine::PAX, PlanLine::SERVICE])],
            'month' => ['required', 'integer', Rule::in(PlanBuilder::openMonths($plan->base_year))],
            'volume' => ['nullable', 'numeric', 'min:0'],
            'capacity' => ['nullable', 'numeric', 'min:0'],
            'load' => ['nullable', 'numeric', 'between:0,1.5'],
            'unit_net' => ['nullable', 'numeric'],
            'unit_margin' => ['nullable', 'numeric'],
            'unit_cost' => ['nullable', 'numeric'],
            'amount' => ['nullable', 'numeric'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->allow($request, $plan, $validated['kind'], $validated['key'], $validated['label'] ?? null);

        BaseEstimate::query()->updateOrCreate(
            [
                'company_id' => $plan->company_id,
                'year' => $plan->base_year,
                'kind' => $validated['kind'],
                'key' => $validated['key'],
                'month' => $validated['month'],
            ],
            [
                ...array_filter($validated, fn ($value) => $value !== null),
                'unit' => $validated['unit'] ?? PlanLine::PAX,
                'created_by_id' => $request->user()?->id,
            ],
        );

        // Planul pornește din anul de bază, deci se reașază pe loc — fără să
        // calce peste ce s-a scris de mână pe pârghiile lui.
        $this->builder->seed($plan);

        return back();
    }

    /** Înapoi la estimarea automată pentru luna aia. */
    public function resetBase(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in([PlanLine::CATEGORY, PlanLine::CHARTER, PlanLine::OPEX, PlanLine::BELOW, PlanLine::RETAIL])],
            'key' => ['required', 'string', 'max:255'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);

        $this->allow($request, $plan, $validated['kind'], $validated['key']);

        BaseEstimate::query()
            ->where('company_id', $plan->company_id)
            ->where('year', $plan->base_year)
            ->where('kind', $validated['kind'])
            ->where('key', $validated['key'])
            ->when(isset($validated['month']), fn ($query) => $query->where('month', $validated['month']))
            ->delete();

        $this->builder->seed($plan);

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf(
            'Estimarea pentru %d s-a întors la cifrele socotite singure.', $plan->base_year,
        )]);

        return back();
    }

    /**
     * Planul scos din aplicație: Excel, hârtie sau prezentare.
     *
     * Toate trei pleacă din aceeași strângere de date, ca să nu se contrazică
     * între ele, și toate trei duc cu ele ipotezele — un buget fără ipoteze
     * nu poate fi apărat de nimeni în ședință.
     */
    public function export(
        Request $request,
        Plan $plan,
        PlanExport $export,
        PlanPdfWriter $pdf,
        PptxWriter $pptx,
    ): HttpResponse {
        $this->boss($request);

        $format = in_array($request->string('format')->toString(), PlanExport::FORMATS, true)
            ? $request->string('format')->toString()
            : 'xlsx';

        $version = $request->integer('version')
            ? $plan->versions()->whereKey($request->integer('version'))->with('createdBy:id,name')->first()
            : null;

        [$plan, $period, $basis, $mode] = $this->reading($request, $plan, $version);

        $output = $this->engine->compute($plan, $period, $basis, $mode);
        $name = $export->filename($plan, $version);

        if ($format === 'xlsx') {
            return XlsxWorkbook::streamDownload($name.'.xlsx', $export->sheets($plan, $output, $version));
        }

        if ($format === 'pdf') {
            return new HttpResponse($pdf->render($export->deck($plan, $output, $version), $export->summary($plan, $output)), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"',
            ]);
        }

        return new HttpResponse($pptx->render($export->slides($plan, $output, $version), sprintf('Plan %d', $plan->year)), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'Content-Disposition' => 'attachment; filename="'.$name.'.pptx"',
        ]);
    }

    /**
     * Cum se citește planul la export: de pe pagină, sau din varianta cerută.
     *
     * Varianta nu se încarcă în plan ca s-o putem scoate pe hârtie — ar
     * însemna să calce peste ce lucrează omul acum. Pârghiile ei se pun pe un
     * plan ținut în memorie, iar baza de date rămâne cum era.
     *
     * @return array{0: Plan, 1: string, 2: string, 3: string}
     */
    private function reading(Request $request, Plan $plan, ?PlanVersion $version): array
    {
        if ($version !== null) {
            $plan = $plan->replicate(['id']);
            $plan->id = $version->plan_id;
            $plan->setRelation('lines', collect($version->lines)->map(fn (array $row) => new PlanLine($row)));

            return [$plan, $version->period, $version->basis, $version->mode];
        }

        $period = preg_match('/^(ytd|mtd)([1-9]|1[0-2])$/', (string) $request->string('period')) === 1
            ? (string) $request->string('period')
            : 'ytd12';
        $basis = in_array($request->string('basis')->toString(), PnlReportService::BASES, true)
            ? $request->string('basis')->toString()
            : PnlReportService::BASIS_RAS;
        $mode = in_array($request->string('mode')->toString(), PnlReportService::MODES, true)
            ? $request->string('mode')->toString()
            : PnlReportService::MODE_OPERATIONAL;

        return [$plan, $period, $basis, $mode];
    }

    public function destroy(Request $request, Plan $plan): RedirectResponse
    {
        $this->boss($request);

        $plan->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Planul a fost șters.']);

        return redirect()->route('reports.forecast.index');
    }
}
