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
            'counterparty' => ['nullable', 'string', 'max:120'],
            'buyer' => ['nullable', 'string', 'max:120'],
            'direction' => ['required', Rule::in(CharterContract::DIRECTIONS)],
            'in_cash_flow' => ['required', 'boolean'],
            'contract_no' => ['nullable', 'string', 'max:60'],
            'signed_date' => ['nullable', 'date'],
            'period_from' => ['nullable', 'date'],
            'period_to' => ['nullable', 'date', 'after_or_equal:period_from'],
            'season' => ['required', 'string', 'max:20'],
            'status' => ['required', Rule::in(CharterContract::STATUSES)],
            'operator' => ['nullable', 'string', 'max:80'],
            'currency' => ['required', 'string', 'size:3'],
            'days_before_flight' => ['required', 'integer', 'between:0,120'],
            'payment_basis' => ['required', Rule::in(CharterContract::PAYMENT_BASES)],
            'taxes_rule' => ['required', Rule::in(CharterContract::TAXES_RULES)],
            'taxes_days' => ['nullable', 'integer', 'between:0,120'],
            'taxes_month_day' => ['required', 'integer', 'between:1,28'],
            'deposit_percent' => ['nullable', 'numeric', 'between:0,100'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'deposit_due_date' => ['nullable', 'date'],
            'deposit_paid' => ['required', 'boolean'],
            'deposit_settlement' => ['nullable', 'string', 'max:60'],
            'contract_value' => ['nullable', 'numeric', 'min:0'],
            'contract_value_with_taxes' => ['nullable', 'numeric', 'min:0'],
            'invoicing' => ['nullable', 'string', 'max:255'],
            'fuel_rule' => ['nullable', 'string', 'max:400'],
            'fx_markup_pct' => ['required', 'numeric', 'between:0,20'],
            'late_penalty_pct_per_day' => ['nullable', 'numeric', 'between:0,10'],
            'cancellation_terms' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', 'string', 'max:255'],
            'confidence' => ['nullable', Rule::in(['R', 'M', 'S'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['currency'] = strtoupper($validated['currency']);

        return $validated;
    }
}
