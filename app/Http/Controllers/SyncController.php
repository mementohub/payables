<?php

namespace App\Http\Controllers;

use App\Jobs\SyncCompanyJob;
use App\Models\Company;
use App\Services\SyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Throwable;

class SyncController extends Controller
{
    /**
     * Sync one company: inline for a short range (no queue worker needed),
     * through Horizon day by day for a long one.
     */
    public function store(Request $request, Company $company, SyncService $sync): RedirectResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;

        $days = ($from && $to)
            ? max(1, (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1)
            : (int) config('sync.recent_days', 3);

        if ($days <= (int) config('sync.inline_max_days', 7)) {
            try {
                $result = ($from && $to)
                    ? $sync->sync($company, Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay())
                    : $sync->syncRecent($company);
            } catch (Throwable $e) {
                Inertia::flash('toast', ['type' => 'error', 'message' => $this->failure($company, $e)]);

                return back();
            }

            Inertia::flash('toast', ['type' => 'success', 'message' => $this->summary($company, $result)]);

            return back();
        }

        SyncCompanyJob::dispatch($company, $from, $to);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Sincronizare pornită: {$days} zile vor fi procesate paralel prin Horizon.",
        ]);

        return back();
    }

    /**
     * Pull the recent days of every company inline and refresh their open
     * invoices, the same thing the scheduler does every ten minutes.
     */
    public function storeAll(SyncService $sync): RedirectResponse
    {
        $messages = [];
        $failed = false;

        foreach (Company::query()->orderBy('name')->get() as $company) {
            try {
                $messages[] = $this->summary($company, $sync->syncRecent($company));
            } catch (Throwable $e) {
                $failed = true;
                $messages[] = $this->failure($company, $e);
            }
        }

        Inertia::flash('toast', [
            'type' => $failed ? 'error' : 'success',
            'message' => $messages === [] ? 'Nicio companie de sincronizat.' : implode(' · ', $messages),
        ]);

        return back();
    }

    /**
     * @param  array<string, int>  $result
     */
    private function summary(Company $company, array $result): string
    {
        return sprintf(
            '%s: %d facturi, %d plăți, %d parteneri sincronizate%s.',
            $company->name,
            $result['invoices'],
            $result['payments'],
            $result['partners'],
            isset($result['refreshed']) ? sprintf(', %d facturi deschise actualizate', $result['refreshed']) : '',
        );
    }

    private function failure(Company $company, Throwable $e): string
    {
        return "{$company->name}: sincronizarea a eșuat – ".trim(($e->getPrevious() ?? $e)->getMessage());
    }
}
