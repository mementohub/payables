<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\ForecastHistory;
use App\Models\Plan;
use App\Models\PlanLine;
use App\Services\Forecast\PlanBuilder;
use App\Services\Forecast\PlanEngine;
use App\Services\Reports\PnlReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

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
    public function __construct(private PlanBuilder $builder, private PlanEngine $engine) {}

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

        // Aceleași comenzi ca pe P&L: perioada și baza chiriilor.
        $period = preg_match('/^(year|q[1-4]|(ytd|mtd)([1-9]|1[0-2]))$/', (string) $request->string('period')) === 1
            ? (string) $request->string('period')
            : 'year';
        $basis = in_array($request->string('basis')->toString(), PnlReportService::BASES, true)
            ? $request->string('basis')->toString()
            : PnlReportService::BASIS_RAS;

        return Inertia::render('reports/forecast', [
            'companies' => $companies,
            'filters' => [
                'company_id' => $companyId,
                'plan' => $plan?->id,
                'period' => $period,
                'basis' => $basis,
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
            'output' => $plan ? $this->engine->compute($plan, $period, $basis) : null,
            // Trei ani în urmă, pe fiecare rând: altfel gradul de ocupare și
            // banii pe pasager s-ar hotărî dintr-un singur an, care poate să
            // fi fost bun sau prost din întâmplare.
            'history' => $plan ? $this->history($plan) : [],
            'historyYears' => $plan ? $this->historyYears($plan) : [],
            'years' => range((int) now()->year + 1, (int) now()->year - 2),
        ]);
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

        $out = [];

        foreach ($rows as $row) {
            $key = $row->kind.'|'.$row->key;
            $cell = $out[$key][$row->year] ?? ['volume' => 0.0, 'capacity' => 0.0, 'sold' => 0.0, 'net' => 0.0, 'margin' => 0.0];

            $out[$key][$row->year] = [
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

    public function destroy(Plan $plan): RedirectResponse
    {
        $plan->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Planul a fost șters.']);

        return redirect()->route('reports.forecast.index');
    }
}
