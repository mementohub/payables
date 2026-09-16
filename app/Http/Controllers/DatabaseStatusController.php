<?php

namespace App\Http\Controllers;

use App\Services\DatabaseStatusService;
use Inertia\Inertia;
use Inertia\Response;

class DatabaseStatusController extends Controller
{
    public function index(DatabaseStatusService $databaseStatus): Response
    {
        return Inertia::render('database-status/index', [
            'status' => Inertia::defer(fn () => [
                'connections' => $databaseStatus->statuses(),
                'checked_at' => now()->toIso8601String(),
            ]),
        ]);
    }
}
