<?php

namespace App\Http\Controllers;

use App\Models\BaseEstimate;
use App\Models\Company;
use App\Models\ForecastHistory;
use App\Models\Plan;
use App\Models\PlanLine;
use App\Models\PlanVersion;
use App\Services\Exports\PlanExport;
use App\Services\Exports\PlanPdfWriter;
use App\Services\Exports\PptxWriter;
use App\Services\Forecast\PlanBuilder;
use App\Services\Forecast\PlanEngine;
use App\Services\Reports\PnlReportService;
use App\Services\Xlsx\XlsxWorkbook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
                'status' => $plan->status,
                'note' => $plan->note,
            ] : null,
            'output' => $plan ? $this->engine->compute($plan, $period, $basis, $mode) : null,
            // Variantele salvate ale planului: la ele se întoarce omul când
            // vrea să vadă ce propunea acum două săptămâni.
            'versions' => $plan ? $plan->versions()->with('createdBy:id,name')->get()->map(fn (PlanVersion $version) => [
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
            'history' => $plan ? $this->history($plan) : [],
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
    private function costHistory(Plan $plan): array
    {
        $out = [];

        foreach ($this->historyYears($plan) as $year) {
            $report = $this->pnl->cached($plan->company, $year);

            if ($report === null || isset($report['error'])) {
                continue;
            }

            $buckets = [];

            foreach (($report['lines'] ?? []) as $line) {
                $buckets[PlanLine::OPEX.'|'.$line['saf']] = $line['months'] ?? [];
            }

            foreach (['amortizare', 'financiar', 'impozit'] as $bucket) {
                $buckets[PlanLine::BELOW.'|'.$bucket] = $report['below'][$bucket]['months'] ?? [];
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

        return $out;
    }

    /**
     * Cum a mers fiecare rând în anii dinainte.
     *
     * @return array<string, array<int, array<string, float>>>
     */
    private function history(Plan $plan): array
    {
        $rows = ForecastHistory::query()
            ->where('company_id', $plan->company_id)
            ->whereIn('year', $this->historyYears($plan))
            ->get();

        // Cheltuielile n-au istoric strâns în `forecast_history`: ele stau în
        // rapoartele construite ale anilor dinainte, linie cu linie. Fără
        // ele, coloanele 2024–2026 de pe fila „Cheltuieli” ar fi goale, iar
        // omul n-ar avea pe ce judeca o creștere.
        $out = $this->costHistory($plan);

        foreach ($rows as $row) {
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

        return $out;
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

        return [$last, $last - 1, $last - 2];
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'year' => ['required', 'integer', 'between:2024,2100'],
            'base_year' => ['required', 'integer', 'between:2020,2100'],
            'name' => ['required', 'string', 'max:80'],
        ]);

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

    /** Pornește din nou din istoric, fără să calce peste ce s-a scris de mână. */
    public function reseed(Request $request, Plan $plan): RedirectResponse
    {
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
            'kind' => ['required', Rule::in([PlanLine::CATEGORY, PlanLine::CHARTER, PlanLine::OPEX, PlanLine::BELOW])],
            'key' => ['required', 'string', 'max:255'],
            'field' => ['required', Rule::in(['volume', 'capacity', 'load', 'unit_net', 'unit_margin', 'amount'])],
            'value' => ['nullable', 'numeric'],
            'growth' => ['nullable', 'numeric', 'between:-100,500'],
        ]);

        $lines = $plan->lines()->where('kind', $validated['kind'])->where('key', $validated['key'])->get();

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
            'cells.*.field' => ['required', Rule::in(['volume', 'capacity', 'load', 'unit_net', 'unit_margin', 'unit_cost', 'amount'])],
            'cells.*.value' => ['required', 'numeric'],
            'rows' => ['array'],
            'rows.*.kind' => ['required', Rule::in([PlanLine::CATEGORY, PlanLine::CHARTER, PlanLine::OPEX, PlanLine::BELOW])],
            'rows.*.key' => ['required', 'string', 'max:255'],
            'rows.*.field' => ['required', Rule::in(['volume', 'capacity', 'load', 'unit_net', 'unit_margin', 'unit_cost', 'amount'])],
            'rows.*.value' => ['nullable', 'numeric'],
            'rows.*.growth' => ['nullable', 'numeric', 'between:-100,500'],
        ]);

        $cells = 0;
        $rows = 0;

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
    public function restoreVersion(Plan $plan, PlanVersion $version): RedirectResponse
    {
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

    public function destroyVersion(Plan $plan, PlanVersion $version): RedirectResponse
    {
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
     * O lună din anul de bază pe care n-am trăit-o încă, scrisă de mână.
     *
     * Planul lui 2027 pornește din 2026, dar 2026 are, în octombrie, nouă
     * luni adevărate și trei care vin. Până acum ultimele trei se ghiceau
     * singure; acum și le scrie omul, iar planul se reașază sub ele.
     */
    public function updateBase(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in([PlanLine::CATEGORY, PlanLine::CHARTER, PlanLine::OPEX, PlanLine::BELOW])],
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
            'kind' => ['required', Rule::in([PlanLine::CATEGORY, PlanLine::CHARTER, PlanLine::OPEX, PlanLine::BELOW])],
            'key' => ['required', 'string', 'max:255'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);

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

    public function destroy(Plan $plan): RedirectResponse
    {
        $plan->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Planul a fost șters.']);

        return redirect()->route('reports.forecast.index');
    }
}
