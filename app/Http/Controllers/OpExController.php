<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Reports\OpExReportService;
use Illuminate\Http\JsonResponse;
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

    public function invoices(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'compare_year' => ['nullable', 'integer', 'between:2000,2100'],
            'leaves' => ['required', 'array', 'min:1'],
            'leaves.*' => ['string', 'max:255'],
        ]);

        $year = (int) $validated['year'];
        $leaves = $validated['leaves'];

        $rows = $this->service->invoicesForLeaves($company, $year, $leaves);
        $payload = ['year' => $year, 'invoices' => $rows];

        $compareYear = isset($validated['compare_year']) ? (int) $validated['compare_year'] : null;
        if ($compareYear && $compareYear !== $year) {
            $payload['compare_year'] = $compareYear;
            $payload['invoices_prev'] = $this->service->invoicesForLeaves($company, $compareYear, $leaves);
        }

        return response()->json($payload);
    }
}
