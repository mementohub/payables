<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsRemote;
use App\Models\Company;
use App\Models\EtripSupplier;
use App\Models\Partner;
use App\Services\Etrip\EtripReader;
use App\Services\Etrip\EtripSupplierSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

class EtripSupplierController extends Controller
{
    use ReadsRemote;

    /**
     * The company's active eTrip suppliers, read live from eTrip and joined with
     * the partner each one is linked to locally. Without a search term the whole
     * list is returned so the client can filter it.
     *
     * @return array{suppliers: list<array{code: string, name: string, currency: ?string, partner_id: ?int}>}
     */
    public function search(Request $request, Company $company, EtripReader $reader): array
    {
        abort_if($company->etripConnection() === null, 422, 'Compania nu este legată de o bază eTrip.');

        $term = Str::lower($request->string('q')->trim()->toString());

        $links = EtripSupplier::query()
            ->where('company_id', $company->id)
            ->whereNotNull('partner_id')
            ->pluck('partner_id', 'code');

        $suppliers = collect($this->readingEtrip(fn () => $reader->suppliers($company)))
            ->filter(fn (array $supplier) => $supplier['active'])
            ->when($term !== '', fn ($suppliers) => $suppliers->filter(
                fn (array $supplier) => str_contains(Str::lower($supplier['name']), $term) || $supplier['code'] === $term,
            ))
            ->map(fn (array $supplier) => [
                'code' => $supplier['code'],
                'name' => $supplier['name'],
                'currency' => $supplier['currency'],
                'partner_id' => isset($links[$supplier['code']]) ? (int) $links[$supplier['code']] : null,
            ])
            ->values();

        return ['suppliers' => $suppliers->all()];
    }

    /**
     * Refresh the local mirror now (used for CUI / name matching) and report
     * what was matched. Runs inline so it needs no queue worker.
     */
    public function sync(Company $company, EtripSupplierSyncService $sync): RedirectResponse
    {
        if ($company->etripConnection() === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Compania nu este legată de o bază eTrip.']);

            return back();
        }

        try {
            $result = $sync->sync($company);
        } catch (Throwable $e) {
            $cause = $e->getPrevious() ?? $e;

            Inertia::flash('toast', ['type' => 'error', 'message' => 'Baza eTrip nu poate fi accesată: '.trim($cause->getMessage())]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => sprintf(
                '%d furnizori eTrip; %d potriviți după CUI, %d după nume, %d fără partener.',
                $result['synced'],
                $result['matched_cui'],
                $result['matched_name'],
                $result['unmatched'],
            ),
        ]);

        return back();
    }

    public function link(Request $request, Partner $partner, EtripSupplierSyncService $sync): RedirectResponse
    {
        $validated = $request->validate([
            'etrip_supplier_code' => ['required', 'string', 'max:50'],
        ]);

        $company = $partner->company;
        abort_if($company->etripConnection() === null, 422, 'Compania nu este legată de o bază eTrip.');

        $supplier = $this->readingEtrip(fn () => $sync->remember($company, $validated['etrip_supplier_code']));

        if ($supplier === null) {
            throw ValidationException::withMessages(['etrip_supplier_code' => 'Furnizorul nu există în eTrip.']);
        }

        DB::transaction(function () use ($partner, $supplier) {
            EtripSupplier::query()->where('partner_id', $partner->id)->update(['partner_id' => null, 'match_source' => null]);

            $supplier->update(['partner_id' => $partner->id, 'match_source' => EtripSupplier::MATCH_MANUAL]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Furnizorul eTrip a fost legat.']);

        return back();
    }

    public function unlink(Partner $partner): RedirectResponse
    {
        EtripSupplier::query()->where('partner_id', $partner->id)->update(['partner_id' => null, 'match_source' => null]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Legătura cu furnizorul eTrip a fost ștearsă.']);

        return back();
    }
}
