<?php

namespace App\Services\Maintenance;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Throwable;

/**
 * Runs `php artisan app:upgrade` from the browser, for installations nobody
 * can reach over SSH: detached in the background with its output in a log
 * file the maintenance page follows, or the migrations alone, inline, when a
 * background process cannot be started.
 */
class UpgradeRunner
{
    public const STATE = 'maintenance:upgrade';

    /** Written as the last log line by the background run, with the exit code. */
    private const SENTINEL = '__EXIT:';

    /** A run without an end after this long is reported as lost, not running. */
    public const STALE_MINUTES = 90;

    private const LOG_LINES = 200;

    public function __construct(private ?string $logPath = null) {}

    public function logPath(): string
    {
        return $this->logPath ?? storage_path('logs/upgrade.log');
    }

    /**
     * Start app:upgrade detached from the request; the shell appends the exit
     * code to the log when it ends.
     */
    public function start(?string $startedBy = null): void
    {
        $php = (new PhpExecutableFinder)->find(false) ?: 'php';
        $script = sprintf(
            'cd %s && %s artisan app:upgrade --no-interaction --no-ansi; echo "%s$?"',
            escapeshellarg(base_path()),
            escapeshellarg($php),
            self::SENTINEL,
        );
        $command = sprintf('nohup sh -c %s >> %s 2>&1 &', escapeshellarg($script), escapeshellarg($this->logPath()));

        File::ensureDirectoryExists(dirname($this->logPath()));
        File::put($this->logPath(), '');

        try {
            $result = Process::path(base_path())->timeout(20)->run($command);
        } catch (Throwable $e) {
            throw new RuntimeException(trim($e->getMessage()), 0, $e);
        }

        if ($result->failed()) {
            throw new RuntimeException(trim($result->errorOutput() ?: $result->output()) ?: 'Procesul nu a putut fi pornit.');
        }

        Cache::forever(self::STATE, ['started_at' => now()->toIso8601String(), 'mode' => 'background', 'by' => $startedBy]);
    }

    /**
     * Only the migrations, in this request; quick, and enough to unblock a
     * deploy when the background run is not possible.
     *
     * @return array{output: string, exit_code: int}
     */
    public function migrateInline(?string $startedBy = null): array
    {
        $exit = Artisan::call('migrate', ['--force' => true]);
        $output = $this->plain(trim(Artisan::output()));

        File::ensureDirectoryExists(dirname($this->logPath()));
        File::put($this->logPath(), $output."\n".self::SENTINEL.$exit."\n");
        Cache::forever(self::STATE, ['started_at' => now()->toIso8601String(), 'mode' => 'inline', 'by' => $startedBy]);

        return ['output' => $output, 'exit_code' => $exit];
    }

    /**
     * @return array{running: bool, stale: bool, mode: ?string, started_at: ?string, started_by: ?string, exit_code: ?int, log: string}
     */
    public function status(): array
    {
        $state = (array) Cache::get(self::STATE, []);
        $log = File::exists($this->logPath()) ? (string) File::get($this->logPath()) : '';
        $exit = preg_match('/'.preg_quote(self::SENTINEL, '/').'(\d+)\s*$/', $log, $matches) ? (int) $matches[1] : null;
        $startedAt = isset($state['started_at']) ? Carbon::parse($state['started_at']) : null;
        $stale = $startedAt !== null && $exit === null && $startedAt->lt(now()->subMinutes(self::STALE_MINUTES));

        return [
            'running' => $startedAt !== null && $exit === null && ! $stale,
            'stale' => $stale,
            'mode' => $state['mode'] ?? null,
            'started_at' => $startedAt?->toIso8601String(),
            'started_by' => $state['by'] ?? null,
            'exit_code' => $exit,
            'log' => $this->tail($this->plain(preg_replace('/'.preg_quote(self::SENTINEL, '/').'\d+\s*$/', '', $log) ?? $log)),
        ];
    }

    /**
     * Migrations on disk that the database has not run yet.
     *
     * @return list<string>
     */
    public function pendingMigrations(): array
    {
        $migrator = app('migrator');
        $files = $migrator->getMigrationFiles([...$migrator->paths(), database_path('migrations')]);
        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];

        return array_values(array_diff(array_keys($files), $ran));
    }

    private function tail(string $text): string
    {
        $lines = preg_split('/\r?\n/', rtrim($text)) ?: [];

        return implode("\n", array_slice($lines, -self::LOG_LINES));
    }

    private function plain(string $text): string
    {
        return preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $text) ?? $text;
    }
}
