<?php

namespace App\Http\Controllers;

use App\Jobs\SyncEtripSuppliersJob;
use App\Models\Company;
use App\Models\EtripSupplier;
use App\Models\Partner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class EtripSupplierController extends Controller
{
    /**
     * Active eTrip suppliers of a company, for pickers. Without a search term the
     * whole list is returned so the client can filter locally.
     *
     * @return array{suppliers: list<array{id: int, code: string, name: string, currency: ?string, partner_id: ?int}>}
     */
    public function search(Request $request, Company $company): array
    {
        $term = $request->string('q')->trim()->toString();

        $suppliers = EtripSupplier::query()
            ->where('company_id', $company->id)
            ->active()
            ->when($term !== '', function ($query) use ($term) {
                $query->where(function ($query) use ($term) {
                    $query->where('name', 'like', "%{$term}%")->orWhere('code', $term);
                });
            })
            ->orderBy('name')
            ->limit($term !== '' ? 50 : 10000)
            ->get(['id', 'code', 'name', 'currency', 'partner_id'])
            ->map(fn (EtripSupplier $supplier) => [
                'id' => $supplier->id,
                'code' => $supplier->code,
                'name' => $supplier->name,
                'currency' => $supplier->currency,
                'partner_id' => $supplier->partner_id,
            ]);

        return ['suppliers' => $suppliers->all()];
    }

    public function sync(Company $company): RedirectResponse
    {
        if ($company->etripConnection() === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Compania nu este legată de o bază eTrip.']);

            return back();
        }

        SyncEtripSuppliersJob::dispatch($company);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sincronizarea furnizorilor eTrip a pornit în fundal.']);

        return back();
    }

    public function link(Request $request, Partner $partner): RedirectResponse
    {
        $validated = $request->validate([
            'etrip_supplier_id' => [
                'required',
                'integer',
                Rule::exists('etrip_suppliers', 'id')->where('company_id', $partner->company_id),
            ],
        ]);

        DB::transaction(function () use ($partner, $validated) {
            EtripSupplier::query()->where('partner_id', $partner->id)->update(['partner_id' => null, 'match_source' => null]);

            EtripSupplier::query()->whereKey($validated['etrip_supplier_id'])->update([
                'partner_id' => $partner->id,
                'match_source' => EtripSupplier::MATCH_MANUAL,
            ]);
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
