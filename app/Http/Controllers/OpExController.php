<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Reports\OpExReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OpExController extends Controller
{
    public function __construct(private readonly OpExReportService $service) {}

    public function index(Request $request): Response
    {
        $companies = Company::orderBy('name')->get(['id', 'name']);

        $companyId = $request->integer('company_id') ?: $companies->first()?->id;
        $year = $request->integer('year') ?: (int) now()->year;
        $compareYear = $request->integer('compare_year') ?: null;
        if ($compareYear === $year) {
            $compareYear = null;
        }

        $report = null;
        if ($companyId) {
            $company = Company::find($companyId);
            if ($company) {
                $current = $this->service->report($company, $year);
                if ($compareYear) {
                    $previous = $this->service->report($company, $compareYear);
                    $report = $this->service->compareReports($current, $previous);
                } else {
                    $report = $current;
                }
            }
        }

        return Inertia::render('reports/opex', [
            'companies' => $companies,
            'filters' => [
                'company_id' => $companyId,
                'year' => $year,
                'compare_year' => $compareYear,
            ],
            'report' => $report,
        ]);
    }

    public function refresh(Request $request, Company $company): RedirectResponse
    {
        $year = $request->integer('year') ?: (int) now()->year;
        $this->service->clearCache($company, $year);

        return back(303);
    }

    public function invoices(Request $request, Company $company): Response
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'leaves' => ['required', 'array', 'min:1'],
            'leaves.*' => ['string', 'max:255'],
            'sediu' => ['nullable', 'string', 'max:255'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'tip_doc' => ['nullable', 'string', 'max:50'],
            'partner' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
            'category_label' => ['nullable', 'string', 'max:500'],
        ]);

        $year = (int) $validated['year'];
        $leaves = array_values($validated['leaves']);
        $sediu = array_key_exists('sediu', $validated) ? (string) $validated['sediu'] : null;

        $rows = $this->service->invoicesForLeaves($company, $year, $leaves, $sediu);

        $month = isset($validated['month']) ? (int) $validated['month'] : null;
        $tipDoc = $validated['tip_doc'] ?? null;
        $partner = $validated['partner'] ?? null;
        $q = $validated['q'] ?? null;

        $filtered = array_values(array_filter($rows, function (array $r) use ($month, $tipDoc, $partner, $q) {
            if ($month !== null && (int) $r['month'] !== $month) {
                return false;
            }
            if ($tipDoc !== null && $tipDoc !== '' && $r['tip_doc'] !== $tipDoc) {
                return false;
            }
            if ($partner !== null && $partner !== '' && $r['partner'] !== $partner) {
                return false;
            }
            if ($q !== null && $q !== '') {
                $needle = mb_strtolower($q);
                $haystack = mb_strtolower((string) $r['partner'].' '.$r['nr_doc']);
                if (! str_contains($haystack, $needle)) {
                    return false;
                }
            }

            return true;
        }));

        $tipDocOptions = array_values(array_unique(array_map(fn ($r) => $r['tip_doc'], $rows)));
        sort($tipDocOptions);

        $partnerOptions = array_values(array_unique(array_filter(array_map(fn ($r) => $r['partner'], $rows), fn ($p) => $p !== '' && $p !== null)));
        sort($partnerOptions);

        $totalLei = array_sum(array_map(fn ($r) => (float) $r['line_total_lei'], $filtered));

        return Inertia::render('reports/opex-invoices', [
            'company' => ['id' => $company->id, 'name' => $company->name],
            'filters' => [
                'year' => $year,
                'leaves' => $leaves,
                'sediu' => $sediu,
                'month' => $month,
                'tip_doc' => $tipDoc,
                'partner' => $partner,
                'q' => $q,
                'category_label' => $validated['category_label'] ?? null,
            ],
            'invoices' => $filtered,
            'options' => [
                'tip_doc' => $tipDocOptions,
                'partners' => $partnerOptions,
            ],
            'summary' => [
                'count_total' => count($rows),
                'count_filtered' => count($filtered),
                'total_lei' => round($totalLei, 2),
            ],
        ]);
    }
}
