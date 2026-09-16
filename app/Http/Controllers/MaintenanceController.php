<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Maintenance\ArtisanRunner;
use App\Services\Maintenance\AutoSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Setări → Întreținere: run app:upgrade from the browser and see whether the
 * scheduler and the ERP sync are doing their job.
 */
class MaintenanceController extends Controller
{
    /** The scheduler is considered down after this long without a heartbeat. */
    public const HEARTBEAT_MINUTES = 3;

    public function index(ArtisanRunner $runner, AutoSync $autoSync): Response
    {
        $beat = Cache::get('scheduler:heartbeat');

        return Inertia::render('maintenance/index', [
            'pendingMigrations' => $runner->pendingMigrations(),
            'upgrade' => $runner->status(ArtisanRunner::UPGRADE),
            'syncRun' => $runner->status(ArtisanRunner::SYNC),
            'scheduler' => [
                'last_beat' => $beat,
                'alive' => $beat !== null && Carbon::parse($beat)->gt(now()->subMinutes(self::HEARTBEAT_MINUTES)),
            ],
            'autoSync' => [
                'mode' => $autoSync->mode(),
                'minutes' => (int) config('sync.auto_minutes', 10),
                'window_days' => (int) config('sync.window_days', 45),
                'nightly_hour' => (int) config('sync.nightly_hour', 2),
            ],
            'sync' => Cache::get('erp:sync:last_run'),
            'companies' => Company::query()->orderBy('name')->get()->map(fn (Company $company) => [
                'id' => $company->id,
                'name' => $company->name,
                'source' => $company->erpConnection()
                    ? config('omc.connections.'.$company->erpConnection()).' (live)'
                    : "{$company->db_host}:{$company->db_port}/{$company->db_database}",
                'last_synced_at' => $company->last_synced_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function upgrade(Request $request, ArtisanRunner $runner): RedirectResponse
    {
        if ($runner->isRunning(ArtisanRunner::UPGRADE)) {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'app:upgrade rulează deja; jurnalul de mai jos se actualizează singur.']);

            return back();
        }

        try {
            $runner->start(ArtisanRunner::UPGRADE, [], $request->user()?->name);
        } catch (Throwable $e) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Procesul nu a putut fi pornit în fundal ('.trim($e->getMessage()).'). Rulează „Doar migrările”, apoi „Sincronizează acum”.',
            ]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'app:upgrade a pornit în fundal; jurnalul de mai jos se actualizează singur.']);

        return back();
    }

    public function stop(Request $request, string $run, ArtisanRunner $runner): RedirectResponse
    {
        abort_unless(in_array($run, [ArtisanRunner::UPGRADE, ArtisanRunner::SYNC], true), 404);

        try {
            $runner->stop($run, $request->user()?->name);
        } catch (Throwable $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Procesul nu a putut fi oprit: '.trim($e->getMessage())]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Procesul a fost oprit; ce apucase să aducă rămâne, restul se reia la următoarea sincronizare.']);

        return back();
    }

    public function migrate(Request $request, ArtisanRunner $runner): RedirectResponse
    {
        try {
            $result = $runner->migrateInline($request->user()?->name);
        } catch (Throwable $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Migrările au eșuat: '.trim($e->getMessage())]);

            return back();
        }

        Inertia::flash('toast', $result['exit_code'] === 0
            ? ['type' => 'success', 'message' => 'Migrările au rulat; vezi jurnalul.']
            : ['type' => 'error', 'message' => 'Migrările au eșuat; vezi jurnalul.']);

        return back();
    }
}
