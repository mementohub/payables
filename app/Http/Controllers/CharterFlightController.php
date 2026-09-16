<?php

namespace App\Http\Controllers;

use App\Models\CharterContract;
use App\Models\CharterFlight;
use App\Services\CashFlow\CharterFlightImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Throwable;

class CharterFlightController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        CharterFlight::query()->create($this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rotația a fost adăugată.']);

        return back();
    }

    public function update(Request $request, CharterFlight $flight): RedirectResponse
    {
        $flight->update($this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rotația a fost actualizată.']);

        return back();
    }

    public function destroy(CharterFlight $flight): RedirectResponse
    {
        $flight->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rotația a fost ștearsă.']);

        return back();
    }

    /**
     * Load a flight programme (.xlsx or .csv) into a contract.
     */
    public function import(Request $request, CharterFlightImporter $importer): RedirectResponse
    {
        $validated = $request->validate([
            'charter_contract_id' => ['required', 'integer', Rule::exists('charter_contracts', 'id')],
            'file' => ['required', 'file', 'max:10240', 'mimes:xlsx,csv,txt'],
            'sheet' => ['nullable', 'string', 'max:60'],
            'replace' => ['required', 'boolean'],
        ]);

        $contract = CharterContract::query()->findOrFail($validated['charter_contract_id']);
        $file = $request->file('file');

        try {
            $result = $importer->import($file->getRealPath(), $file->getClientOriginalName(), $contract, (bool) $validated['replace'], $validated['sheet'] ?? null);
        } catch (Throwable $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Importul a eșuat: '.trim($e->getMessage())]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => sprintf(
                '%d rotații importate în %s%s%s.',
                $result['imported'],
                implode(', ', $result['contracts']),
                $result['replaced'] > 0 ? sprintf(' (%d rotații vechi înlocuite)', $result['replaced']) : '',
                $result['skipped'] > 0 ? sprintf('; %d rânduri fără dată sau valoare sărite', $result['skipped']) : '',
            ),
        ]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'charter_contract_id' => ['required', 'integer', Rule::exists('charter_contracts', 'id')],
            'route' => ['required', 'string', 'max:60'],
            'flight_no' => ['nullable', 'string', 'max:60'],
            'flight_date' => ['required', 'date'],
            'seats' => ['nullable', 'integer', 'between:0,1000'],
            'price_per_seat' => ['nullable', 'numeric', 'min:0'],
            'net_value' => ['required', 'numeric', 'min:0'],
            'taxes' => ['nullable', 'numeric', 'min:0'],
            'pay_date' => ['nullable', 'date'],
            'taxes_pay_date' => ['nullable', 'date'],
        ]);
    }
}
