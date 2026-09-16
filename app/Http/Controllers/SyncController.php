<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Maintenance\ArtisanRunner;
use App\Services\SyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Throwable;

/**
 * Pull ERP documents from the browser. The pull runs as a detached
 * `erp:sync` process whose progress shows in Întreținere; a web request must
 * not wait for it.
 */
class SyncController extends Controller
{
    public function store(Request $request, Company $company, ArtisanRunner $runner, SyncService $sync): RedirectResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;

        $arguments = ['--company='.$company->id];

        if ($from && $to) {
            $arguments[] = '--from='.Carbon::parse($from)->toDateString();
            $arguments[] = '--to='.Carbon::parse($to)->toDateString();
        }

        return $this->launch($request, $runner, $arguments, "Sincronizarea {$company->name} a pornit în fundal", function () use ($sync, $company, $from, $to) {
            $inlineMax = max(1, (int) config('sync.inline_max_days', 2));
            $days = ($from && $to)
                ? max(1, (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1)
                : min($inlineMax, max(1, (int) config('sync.recent_days', 3)));

            if ($days > $inlineMax) {
                return null;
            }

            $result = ($from && $to)
                ? $sync->syncWindow($company, Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay())
                : $sync->syncRecent($company, $days);

            return sprintf(
                '%s: %d facturi, %d plăți, %d parteneri sincronizate, %d facturi deschise actualizate.',
                $company->name,
                $result['invoices'],
                $result['payments'],
                $result['partners'],
                $result['refreshed'],
            );
        });
    }

    /**
     * Every company, the recent days (or `days` back), in the background.
     */
    public function storeAll(Request $request, ArtisanRunner $runner): RedirectResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:400'],
        ]);

        $arguments = isset($validated['days']) ? ['--days='.$validated['days']] : [];
        $what = isset($validated['days']) ? "Sincronizarea ultimelor {$validated['days']} zile a pornit în fundal" : 'Sincronizarea a pornit în fundal';

        return $this->launch($request, $runner, $arguments, $what, fn () => null);
    }

    /**
     * Start the background run, or fall back to the inline closure when the
     * process cannot be started and the closure has something quick to do.
     *
     * @param  list<string>  $arguments
     * @param  callable(): ?string  $inline
     */
    private function launch(Request $request, ArtisanRunner $runner, array $arguments, string $started, callable $inline): RedirectResponse
    {
        if ($runner->isRunning(ArtisanRunner::SYNC)) {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'O sincronizare rulează deja; progresul ei apare în Setări → Întreținere.']);

            return back();
        }

        try {
            $runner->start(ArtisanRunner::SYNC, $arguments, $request->user()?->name);

            Inertia::flash('toast', ['type' => 'success', 'message' => "{$started}; progresul apare în Setări → Întreținere, iar paginile se actualizează pe măsură ce intră datele."]);

            return back();
        } catch (Throwable $e) {
            $cause = trim($e->getMessage());
        }

        try {
            $summary = $inline();
        } catch (Throwable $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Sincronizarea a eșuat: '.trim(($e->getPrevious() ?? $e)->getMessage())]);

            return back();
        }

        Inertia::flash('toast', $summary === null
            ? ['type' => 'error', 'message' => "Procesul nu a putut fi pornit în fundal ({$cause}); alege un interval de cel mult ".(int) config('sync.inline_max_days', 2).' zile ca să ruleze pe loc.']
            : ['type' => 'success', 'message' => "{$summary} (a rulat pe loc: procesul în fundal nu a putut porni – {$cause})"]);

        return back();
    }
}
