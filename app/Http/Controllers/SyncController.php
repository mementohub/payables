<?php

namespace App\Http\Controllers;

use App\Jobs\SyncCompanyJob;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class SyncController extends Controller
{
    public function store(Request $request, Company $company): RedirectResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;

        SyncCompanyJob::dispatch($company, $from, $to);

        $days = ($from && $to)
            ? max(1, Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1)
            : null;

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $days
                ? "Sincronizare pornită: {$days} zile vor fi procesate paralel prin Horizon."
                : 'Sincronizare pornită în fundal prin Horizon.',
        ]);

        return back();
    }
}
