<?php

namespace App\Http\Controllers;

use App\Models\CashFlowOverride;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * Values set by hand on the WCFR report: one line, one week. An empty amount
 * resets the cell to what the automation works out.
 */
class CashFlowOverrideController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $opexKeys = array_column((array) config('cashflow.opex'), 'key');

        $validated = $request->validate([
            'line' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($opexKeys) {
                if (! preg_match('/^[BC]\d{1,2}(\.\d{1,2})?$/', (string) $value) && ! in_array($value, $opexKeys, true)) {
                    $fail('Linia nu poate fi modificată manual.');
                }
            }],
            'week' => ['required', 'date_format:Y-m-d', function (string $attribute, mixed $value, Closure $fail) {
                if (! Carbon::parse($value)->isMonday()) {
                    $fail('Săptămâna trebuie să înceapă într-o zi de luni.');
                }
            }],
            'amount' => ['nullable', 'numeric', 'between:-999999999999,999999999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validated['amount'] === null || $validated['amount'] === '') {
            CashFlowOverride::query()->where('line', $validated['line'])->where('week', $validated['week'])->delete();

            return back();
        }

        CashFlowOverride::query()->updateOrCreate(
            ['line' => $validated['line'], 'week' => $validated['week']],
            ['amount' => round((float) $validated['amount'], 2), 'note' => $validated['note'] ?? null, 'updated_by_id' => $request->user()?->id],
        );

        return back();
    }

    public function destroy(): RedirectResponse
    {
        $count = CashFlowOverride::query()->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => $count === 1
            ? 'Valoarea modificată manual a revenit la calculul automat.'
            : "Cele {$count} valori modificate manual au revenit la calculul automat."]);

        return back();
    }
}
