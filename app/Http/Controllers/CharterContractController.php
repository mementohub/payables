<?php

namespace App\Http\Controllers;

use App\Models\CharterContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CharterContractController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        CharterContract::query()->create($this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Contractul charter a fost adăugat.']);

        return back();
    }

    public function update(Request $request, CharterContract $contract): RedirectResponse
    {
        $contract->update($this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Contractul charter a fost actualizat.']);

        return back();
    }

    public function destroy(CharterContract $contract): RedirectResponse
    {
        $contract->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Contractul și rotațiile lui au fost șterse.']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'season' => ['required', 'string', 'max:20'],
            'status' => ['required', Rule::in(CharterContract::STATUSES)],
            'operator' => ['nullable', 'string', 'max:80'],
            'currency' => ['required', 'string', 'size:3'],
            'days_before_flight' => ['required', 'integer', 'between:0,120'],
            'deposit_percent' => ['nullable', 'numeric', 'between:0,100'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'deposit_due_date' => ['nullable', 'date'],
            'deposit_paid' => ['required', 'boolean'],
            'contract_value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['currency'] = strtoupper($validated['currency']);

        return $validated;
    }
}
