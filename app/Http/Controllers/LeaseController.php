<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Lease;
use App\Services\Reports\Ifrs16Engine;
use App\Services\Reports\Ifrs16Schedule;
use App\Services\Reports\OmcPnlCostReader;
use App\Services\Reports\PnlReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Registrul contractelor de închiriere, pentru IFRS 16.
 *
 * Chiria plătită o știm din OMC; cât ține contractul și la ce rată ne-am
 * împrumuta noi pe termenul ăla nu scrie nicăieri în ERP — se scriu aici, o
 * dată, din contract. De atunci, raportul nu mai estimează: ia graficul din
 * standard.
 */
class LeaseController extends Controller
{
    public function index(Request $request, Ifrs16Schedule $engine, PnlReportService $pnl): Response
    {
        $companies = Company::query()->orderBy('name')->get(['id', 'name']);
        $companyId = $request->integer('company_id') ?: $companies->first()?->id;
        $year = $request->integer('year') ?: (int) now()->year;
        $company = $companyId ? Company::find($companyId) : null;

        $leases = Lease::query()
            ->when($company, fn ($query) => $query->where('company_id', $company->getKey()))
            ->orderByDesc('monthly_payment')
            ->get();

        $built = $company ? $pnl->cached($company, $year) : null;
        $rent = 0.0;

        // Cât s-a cheltuit pe chirii în anul ăsta, din raportul deja
        // construit: față de el se măsoară cât acoperă registrul.
        foreach (($built['lines'] ?? []) as $line) {
            if (in_array((string) $line['saf'], array_map('strval', (array) config('pnl.ifrs16.lines', [])), true)) {
                foreach ($line['months'] as $cell) {
                    $rent += (float) $cell['total'];
                }
            }
        }

        $counted = $leases->where('status', Lease::ACTIVE)->where('exempt', false);

        // Mișcarea anului, în moneda contractelor: de la soldurile cu care
        // s-a închis anul trecut, prin contractele noi, prelungiri,
        // remăsurări și încetări, până la soldurile de acum.
        $roll = app(Ifrs16Engine::class)->rollForward(
            $counted,
            Carbon::create($year, 1, 1),
            Carbon::create($year, 12, 31),
        );

        $totals = $roll['totals'];

        return Inertia::render('reports/leases', [
            'companies' => $companies,
            'filters' => ['company_id' => $companyId, 'year' => $year],
            'leases' => $leases->map(fn (Lease $lease) => [
                'id' => $lease->id,
                'lessor' => $lease->lessor,
                'location' => $lease->location,
                'currency' => $lease->currency,
                'monthly_payment' => (float) $lease->monthly_payment,
                'in_advance' => (bool) $lease->in_advance,
                'starts_on' => $lease->starts_on?->toDateString(),
                'ends_on' => $lease->ends_on?->toDateString(),
                'months' => $lease->months(),
                'discount_rate' => (float) $lease->discount_rate,
                'exempt' => (bool) $lease->exempt,
                'status' => $lease->status,
                'source' => $lease->source,
                'note' => $lease->note,
                // Linia din calculul IFRS 16, cu evenimentul ei.
                'line_no' => $lease->line_no === null ? null : (int) $lease->line_no,
                'event' => $lease->event,
                'event_on' => $lease->event_on?->toDateString(),
                'successor_no' => $lease->successor_no === null ? null : (int) $lease->successor_no,
                'category' => $lease->category,
                'opening_rou' => $lease->opening_rou === null ? null : round((float) $lease->opening_rou, 2),
                'opening_liability' => $lease->opening_liability === null ? null : round((float) $lease->opening_liability, 2),
                'rou_close' => round((float) ($roll['lines'][(string) $lease->line_no]['rou_close'] ?? 0), 2),
                'liability_close' => round((float) ($roll['lines'][(string) $lease->line_no]['liability_close'] ?? 0), 2),
            ])->values(),
            'summary' => [
                'rent' => round($rent, 2),
                'covered' => round((float) $totals['payments'], 2),
                'depreciation' => round((float) $totals['depreciation'], 2),
                'interest' => round((float) $totals['interest'], 2),
                'liability' => round((float) $totals['liability_close'], 2),
                'counted' => $counted->count(),
                'drafts' => $leases->where('status', Lease::DRAFT)->count(),
                // Mișcarea anului, de la un sold la altul.
                'currency' => (string) ($counted->first()?->currency ?? 'EUR'),
                'opening_rou' => round((float) $totals['opening_rou'], 2),
                'opening_liability' => round((float) $totals['opening_liability'], 2),
                'recognised' => round((float) $totals['recognised'], 2),
                'rou_transferred' => round((float) $totals['rou_transferred'], 2),
                'liability_transferred' => round((float) $totals['liability_transferred'], 2),
                'rou_derecognised' => round((float) $totals['rou_derecognised'], 2),
                'liability_derecognised' => round((float) $totals['liability_derecognised'], 2),
                'gain' => round((float) $totals['gain'], 2),
                'rou' => round((float) $totals['rou_close'], 2),
                'current' => round((float) $totals['current'], 2),
                'long_term' => round((float) $totals['long_term'], 2),
                'maturity' => array_map(fn ($value) => round((float) $value, 2), (array) $totals['maturity']),
            ],
        ]);
    }

    public function update(Request $request, Lease $lease): RedirectResponse
    {
        $validated = $request->validate([
            'monthly_payment' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:5'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'discount_rate' => ['required', 'numeric', 'between:0,0.5'],
            'in_advance' => ['required', 'boolean'],
            'exempt' => ['required', 'boolean'],
            'status' => ['required', Rule::in([Lease::DRAFT, Lease::ACTIVE, Lease::ENDED])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $lease->update([...$validated, 'source' => 'manual']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Contractul a fost salvat. Raportul îl ia în seamă după următoarea construcție.']);

        return back();
    }

    public function destroy(Lease $lease): RedirectResponse
    {
        $lease->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Contractul a fost scos din registru.']);

        return back();
    }

    /** Propune contracte din chiria plătită, ca registrul să nu pornească gol. */
    public function propose(Request $request, OmcPnlCostReader $reader): RedirectResponse
    {
        $company = Company::findOrFail($request->integer('company_id'));
        $year = $request->integer('year') ?: (int) now()->year;

        $this->callSilently('leases:propose', ['--company' => $company->getKey(), '--year' => $year]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Am propus contractele din chiria plătită. Completează durata și rata, apoi confirmă-le.']);

        return back();
    }

    private function callSilently(string $command, array $arguments): void
    {
        Artisan::call($command, $arguments);
    }
}
