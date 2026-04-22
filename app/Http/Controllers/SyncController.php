<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\SyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Throwable;

class SyncController extends Controller
{
    public function store(Request $request, Company $company, SyncService $sync): RedirectResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : null;
        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : null;

        try {
            $result = $sync->sync($company, $from, $to);
        } catch (Throwable $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Sincronizare eșuată: '.$e->getMessage()]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Sincronizate {$result['partners']} parteneri, {$result['invoices']} facturi, {$result['details']} rânduri, {$result['bank_accounts']} conturi bancare, {$result['payments']} plăți.",
        ]);

        return to_route('companies.index');
    }
}
