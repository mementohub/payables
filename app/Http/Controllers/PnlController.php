<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PnlCostOverride;
use App\Services\Exports\PnlExport;
use App\Services\Exports\ReportExporter;
use App\Services\Maintenance\ArtisanRunner;
use App\Services\Reports\PnlCostMap;
use App\Services\Reports\PnlReportService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Contul de profit și pierdere: venit și marjă din eTrip, cheltuieli de
 * exploatare din registrul OMC, pe canal de vânzare sau pe categorie de produs,
 * pe an, trimestru sau lună.
 *
 * Raportul NU se construiește în cererea web: interogarea de venit trece de un
 * minut, iar PHP-FPM taie cererea la 60 de secunde. Pagina arată ce e în cache
 * și pornește în fundal construcția când lipsește.
 */
class PnlController extends Controller
{
    public function __construct(
        private readonly PnlReportService $service,
        private readonly PnlCostMap $map,
        private readonly ArtisanRunner $runner,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $companies = Company::orderBy('name')->get(['id', 'name']);

        $companyId = $request->integer('company_id') ?: $companies->first()?->id;
        $year = $request->integer('year') ?: (int) now()->year;
        $view = $request->string('view')->toString() === 'product' ? 'product' : 'channel';
        $period = $this->period($request->string('period')->toString());
        $basis = in_array($request->string('basis')->toString(), PnlReportService::BASES, true)
            ? $request->string('basis')->toString()
            : PnlReportService::BASIS_RAS;
        $mode = in_array($request->string('mode')->toString(), PnlReportService::MODES, true)
            ? $request->string('mode')->toString()
            : PnlReportService::MODE_OPERATIONAL;
        $expand = $request->string('expand')->toString() ?: null;
        $key = in_array($request->string('key')->toString(), PnlReportService::KEYS, true)
            ? $request->string('key')->toString()
            : PnlReportService::KEY_REVENUE;
        $compare = $request->boolean('compare');
        // Evoluția arată anul întreg — lună, trimestru, total — pe aceleași
        // coloane ale vederii; tabelul obișnuit arată o singură perioadă.
        $layout = $request->string('layout')->toString() === 'evolution' ? 'evolution' : 'table';

        $company = $companyId ? Company::find($companyId) : null;
        $built = $company ? $this->service->cached($company, $year) : null;

        if ($company && $built === null) {
            $this->start($company, $year);
        }

        // Anul trecut, aceeași perioadă. Dacă nu e construit, comparația
        // lipsește pur și simplu — nu ținem pagina în loc un minut pentru ea.
        $previousBuilt = $company && $compare ? $this->service->cached($company, $year - 1) : null;

        if ($company && $compare && $previousBuilt === null) {
            $this->start($company, $year - 1);
        }

        return Inertia::render('reports/pnl', [
            'companies' => $companies,
            'filters' => ['company_id' => $companyId, 'year' => $year, 'view' => $view, 'period' => $period, 'basis' => $basis, 'mode' => $mode, 'expand' => $expand, 'compare' => $compare, 'key' => $key, 'layout' => $layout],
            'report' => $built ? $this->service->view($built, $period, $basis, $mode, $expand, $key) : null,
            'previous' => $previousBuilt ? $this->service->view($previousBuilt, $period, $basis, $mode, $expand, $key) : null,
            'evolution' => $built && $layout === 'evolution'
                ? $this->service->evolution($built, $basis, $mode, $expand, $key)
                : null,
            'lines' => $this->map->lines(),
            'overrides' => $company ? $this->overrides($company) : [],
            'pending' => $company ? $this->pending($company, $built) : 0,
            'run' => $company ? $this->runner->status(ArtisanRunner::PNL) : null,
        ]);
    }

    /**
     * Raportul, așa cum se vede pe ecran, într-un fișier: Excel cu cifre
     * adevărate sau PDF cu logo, de trimis mai departe.
     */
    public function export(Request $request, Company $company, PnlExport $export, ReportExporter $exporter): Response|BinaryFileResponse
    {
        $validated = $request->validate([
            'format' => ['required', Rule::in(ReportExporter::FORMATS)],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'view' => ['nullable', Rule::in(['channel', 'product'])],
            'period' => ['nullable', 'string', 'max:10'],
            'basis' => ['nullable', Rule::in(PnlReportService::BASES)],
            'mode' => ['nullable', Rule::in(PnlReportService::MODES)],
            'expand' => ['nullable', 'string', 'max:40'],
            'key' => ['nullable', Rule::in(PnlReportService::KEYS)],
        ]);

        $built = $this->service->cached($company, (int) $validated['year']);

        // Construcția trece de un minut, deci nu se face într-o descărcare; fără
        // raport în cache, omul e trimis înapoi la pagină, care îl pornește.
        if ($built === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Raportul nu e încă construit pentru anul ales. Deschide pagina, așteaptă construcția și încearcă din nou.']);

            return back(303);
        }

        $view = $this->service->view(
            $built,
            $this->period($validated['period'] ?? ''),
            $validated['basis'] ?? PnlReportService::BASIS_RAS,
            $validated['mode'] ?? PnlReportService::MODE_OPERATIONAL,
            $validated['expand'] ?? null,
            $validated['key'] ?? PnlReportService::KEY_REVENUE,
        );

        return $exporter->download(
            $export->document($company, $view, $validated['view'] ?? 'channel'),
            $validated['format'],
        );
    }

    /**
     * Ce s-a adunat într-o linie de cheltuială, pentru perioada afișată.
     *
     * @return array<string, mixed>
     */
    public function details(Request $request, Company $company): array
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'saf' => ['required', 'string', 'max:20'],
            'period' => ['nullable', 'string', 'max:10'],
            // Coloana apăsată: fără ea se arată toată linia, cu ea doar partea
            // care a ajuns acolo.
            'column' => ['nullable', 'string', 'max:80'],
            'axis' => ['nullable', Rule::in(['channel', 'product', 'branch'])],
            // Grupa apăsată („cont · sediu · partener”): documentele ei.
            'item' => ['nullable', 'string', 'max:300'],
        ]);

        return $this->service->costDetails(
            $company,
            (int) $validated['year'],
            $validated['saf'],
            $this->period($validated['period'] ?? ''),
            $validated['column'] ?? null,
            $validated['axis'] ?? 'channel',
            $validated['item'] ?? null,
        );
    }

    /**
     * Mută o cheltuială pe altă linie, pe alt canal sau pe altă categorie de
     * produs: fie toată linia, fie doar cheltuielile cu o anumită semnătură.
     * Corectura bate maparea automată și se păstrează.
     */
    public function move(Request $request, Company $company): RedirectResponse
    {
        $validated = $request->validate([
            'scope' => ['required', Rule::in(PnlCostOverride::SCOPES)],
            'match_key' => ['required', 'string', 'max:255'],
            'saf' => ['nullable', 'string', 'max:20', Rule::in(array_keys($this->map->lines()))],
            'channel' => ['nullable', 'string', Rule::in(array_keys((array) config('pnl.channels')))],
            // Lista de categorii se citește din raportul construit, deci se
            // cere abia când chiar vine o categorie, nu la fiecare mutare.
            'product' => ['nullable', 'string', 'max:60', function (string $attribute, mixed $value, Closure $fail) use ($request, $company) {
                $products = $this->products($company, $request->integer('year') ?: (int) now()->year);

                if ($products !== [] && ! in_array((string) $value, $products, true)) {
                    $fail('Categoria de produs aleasă nu există în raport.');
                }
            }],
            'label' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ]);

        // O corectură goală n-ar muta nimic, dar ar rămâne pe listă.
        if (($validated['saf'] ?? null) === null && ($validated['channel'] ?? null) === null && ($validated['product'] ?? null) === null) {
            throw ValidationException::withMessages([
                'saf' => 'Alegeți linia pe care se mută cheltuiala, canalul, categoria de produs, sau mai multe dintre ele.',
            ]);
        }

        $override = PnlCostOverride::query()->firstOrNew([
            'company_id' => $company->getKey(),
            'scope' => $validated['scope'],
            'match_key' => $validated['match_key'],
        ]);

        // Cele trei axe se pun una câte una, din vederea în care se lucrează:
        // alegerea unui canal nu șterge produsul ales înainte, și invers.
        $override->fill([
            ...array_filter([
                'saf' => $validated['saf'] ?? null,
                'channel' => $validated['channel'] ?? null,
                'product' => $validated['product'] ?? null,
            ], fn (?string $value) => $value !== null),
            'label' => $validated['label'] ?? $override->label,
            'created_by_id' => $request->user()?->getKey(),
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Corectura a fost păstrată. Apasă „Aplică” ca să intre în raport.']);

        return back(303);
    }

    /**
     * Renunță la o corectură și lasă regulile să decidă din nou.
     */
    public function forget(Request $request, Company $company, PnlCostOverride $override): RedirectResponse
    {
        abort_unless($override->company_id === $company->getKey(), 404);

        $override->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Corectura a fost ștearsă. Apasă „Aplică” ca să intre în raport.']);

        return back(303);
    }

    public function refresh(Request $request, Company $company): RedirectResponse
    {
        $this->rebuild($company, $request->integer('year') ?: (int) now()->year);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Raportul se reconstruiește în fundal, cu toate corecturile. Reîncarcă pagina în câteva minute.']);

        return back(303);
    }

    /**
     * Câte corecturi au fost făcute după ce s-a construit raportul pe care îl
     * vezi. Ele sunt păstrate, dar nu se văd în cifre până la reconstruire —
     * o construcție ia peste un minut, așa că se adună și se aplică odată.
     *
     * @param  array<string, mixed>|null  $built
     */
    private function pending(Company $company, ?array $built): int
    {
        $generatedAt = $built['meta']['generated_at'] ?? null;

        return PnlCostOverride::query()
            ->where('company_id', $company->getKey())
            ->when($generatedAt !== null, fn ($q) => $q->where('updated_at', '>', $generatedAt))
            ->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * Categoriile de produs pe care le poate primi o corectură: chiar cele din
     * raportul construit, ca să nu se poată muta o cheltuială pe o categorie
     * care nu există și să dispară din tabel.
     *
     * @return list<string>
     */
    private function products(Company $company, int $year): array
    {
        $report = $this->service->cached($company, $year);

        return is_array($report) ? array_values(array_map('strval', $report['products'] ?? [])) : [];
    }

    private function overrides(Company $company): array
    {
        $lines = $this->map->lines();

        return PnlCostOverride::query()
            ->where('company_id', $company->getKey())
            ->with('createdBy:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PnlCostOverride $override) => [
                'id' => $override->id,
                'updated_at' => $override->updated_at?->toIso8601String(),
                'scope' => $override->scope,
                'match_key' => $override->match_key,
                'saf' => $override->saf,
                'channel' => $override->channel,
                'product' => $override->product,
                'label' => $override->label,
                'target_label' => implode(' · ', array_filter([
                    $override->saf !== null ? ($lines[$override->saf]['label'] ?? $override->saf) : null,
                    $override->channel !== null ? (config('pnl.channels.'.$override->channel) ?? $override->channel) : null,
                    $override->product,
                ])),
                'by' => $override->createdBy?->name,
            ])
            ->all();
    }

    /**
     * Perioada e o lună plus felul în care se citește: `ytd7` (ianuarie–iulie)
     * sau `mtd7` (doar iulie). Formele vechi rămân valide, ca un link salvat
     * să deschidă tot ce deschidea.
     */
    private function period(string $period): string
    {
        return preg_match('/^(year|q[1-4]|(ytd|mtd|m)([1-9]|1[0-2]))$/', $period) === 1 ? $period : 'year';
    }

    private function rebuild(Company $company, int $year): void
    {
        $this->service->clearCache($company, $year);
        $this->start($company, $year);
    }

    private function start(Company $company, int $year): void
    {
        if ($this->runner->isRunning(ArtisanRunner::PNL)) {
            return;
        }

        try {
            $this->runner->start(ArtisanRunner::PNL, ['--company='.$company->getKey(), '--year='.$year]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
