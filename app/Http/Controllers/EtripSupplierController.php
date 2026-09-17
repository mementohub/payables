<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsRemote;
use App\Models\EtripSupplier;
use App\Models\Partner;
use App\Services\Etrip\EtripReader;
use App\Services\Etrip\EtripSupplierSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

/**
 * The suppliers of the eTrip bases defined in config/etrip.php, searched
 * live and mirrored locally so they can be tied to ERP partners.
 */
class EtripSupplierController extends Controller
{
    use ReadsRemote;

    /**
     * The active suppliers of one eTrip base, read live and joined with the
     * partner each one is linked to locally. Without a search term the whole
     * list is returned so the client can filter it.
     *
     * @return array{suppliers: list<array{code: string, name: string, currency: ?string, partner_id: ?int}>}
     */
    public function search(Request $request, string $connection, EtripReader $reader): array
    {
        $this->knownBase($connection);

        $term = Str::lower($request->string('q')->trim()->toString());

        $links = EtripSupplier::query()
            ->forConnection($connection)
            ->whereNotNull('partner_id')
            ->pluck('partner_id', 'code');

        $suppliers = collect($this->readingEtrip(fn () => $reader->suppliers($connection)))
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
     * Refresh the local mirror of one base now (used for CUI / name matching)
     * and report what was matched. Runs inline so it needs no queue worker.
     */
    public function sync(string $connection, EtripSupplierSyncService $sync): RedirectResponse
    {
        $this->knownBase($connection);

        try {
            $result = $sync->sync($connection);
        } catch (Throwable $e) {
            $cause = $e->getPrevious() ?? $e;

            Inertia::flash('toast', ['type' => 'error', 'message' => 'Baza eTrip nu poate fi accesată: '.trim($cause->getMessage())]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $this->summary($connection, $result).'.']);

        return back();
    }

    /**
     * Refresh the mirror of every eTrip base at once.
     */
    public function syncAll(EtripSupplierSyncService $sync): RedirectResponse
    {
        $connections = array_keys((array) config('etrip.connections'));

        if ($connections === []) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Nicio bază eTrip nu este definită.']);

            return back();
        }

        $lines = [];
        $failed = [];

        foreach ($connections as $connection) {
            try {
                $lines[] = $this->summary($connection, $sync->sync($connection));
            } catch (Throwable $e) {
                $cause = $e->getPrevious() ?? $e;
                $failed[] = sprintf('%s: %s', config('etrip.connections.'.$connection, $connection), trim($cause->getMessage()));
            }
        }

        Inertia::flash('toast', [
            'type' => $failed === [] ? 'success' : 'error',
            'message' => implode('; ', [...$lines, ...array_map(fn (string $line) => 'Baza eTrip nu poate fi accesată — '.$line, $failed)]).'.',
        ]);

        return back();
    }

    public function link(Request $request, Partner $partner, EtripSupplierSyncService $sync): RedirectResponse
    {
        $validated = $request->validate([
            'etrip_connection' => ['required', Rule::in(array_keys((array) config('etrip.connections')))],
            'etrip_supplier_code' => ['required', 'string', 'max:50'],
        ]);

        $supplier = $this->readingEtrip(fn () => $sync->remember($validated['etrip_connection'], $validated['etrip_supplier_code'], $partner->company));

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

    private function knownBase(string $connection): void
    {
        abort_unless(array_key_exists($connection, (array) config('etrip.connections')), 404, 'Baza eTrip nu este definită.');
    }

    /**
     * @param  array{synced: int, matched_cui: int, matched_name: int, unmatched: int}  $result
     */
    private function summary(string $connection, array $result): string
    {
        return sprintf(
            '%s: %d furnizori, %d potriviți după CUI, %d după nume, %d fără partener',
            config('etrip.connections.'.$connection, $connection),
            $result['synced'],
            $result['matched_cui'],
            $result['matched_name'],
            $result['unmatched'],
        );
    }
}
