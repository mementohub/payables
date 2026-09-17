<?php

namespace App\Services\Maintenance;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Throwable;

/**
 * Runs long artisan commands from the browser, for installations nobody can
 * reach over SSH: detached in the background, with the output in a log file
 * the maintenance page follows. A web request must never wait for a sync or
 * an upgrade, or nginx gives up on it.
 */
class ArtisanRunner
{
    public const UPGRADE = 'upgrade';

    public const SYNC = 'sync';

    public const CASHFLOW = 'cashflow';

    private const COMMANDS = [
        self::UPGRADE => 'app:upgrade',
        self::SYNC => 'erp:sync',
        self::CASHFLOW => 'cashflow:build',
    ];

    /** Written as the last log line by the background run, with the exit code. */
    private const SENTINEL = '__EXIT:';

    /** A run without an end after this long is reported as lost, not running. */
    public const STALE_MINUTES = 180;

    private const LOG_LINES = 300;

    /** How much of the end of a log file is ever read into memory. */
    private const LOG_BYTES = 262144;

    public function __construct(private ?string $logDirectory = null) {}

    public function logPath(string $run): string
    {
        return ($this->logDirectory ?? storage_path('logs')).'/'.$this->command($run).'.log';
    }

    /**
     * Start the run detached from the request; the shell appends the exit
     * code to the log when it ends.
     *
     * @param  list<string>  $arguments
     */
    public function start(string $run, array $arguments = [], ?string $startedBy = null): void
    {
        $php = (new PhpExecutableFinder)->find(false) ?: 'php';
        $artisan = implode(' ', [
            escapeshellarg($php),
            'artisan',
            $this->command($run),
            ...array_map('escapeshellarg', $arguments),
            '--no-interaction',
            '--no-ansi',
        ]);
        $script = sprintf('cd %s && %s; echo "%s$?"', escapeshellarg(base_path()), $artisan, self::SENTINEL);
        $command = sprintf('nohup sh -c %s >> %s 2>&1 & echo $!', escapeshellarg($script), escapeshellarg($this->logPath($run)));

        File::ensureDirectoryExists(dirname($this->logPath($run)));
        File::put($this->logPath($run), '');

        try {
            $result = Process::path(base_path())->timeout(20)->run($command);
        } catch (Throwable $e) {
            throw new RuntimeException(trim($e->getMessage()), 0, $e);
        }

        if ($result->failed()) {
            throw new RuntimeException(trim($result->errorOutput() ?: $result->output()) ?: 'Procesul nu a putut fi pornit.');
        }

        Cache::forever($this->stateKey($run), [
            'started_at' => now()->toIso8601String(),
            'mode' => 'background',
            'by' => $startedBy,
            'arguments' => $arguments,
            'pid' => (int) trim($result->output()) ?: null,
        ]);
    }

    /**
     * Kill the run: the shell we started and its artisan child, plus any
     * artisan process of that command started before the pid was recorded.
     * The log gets the SIGTERM exit code so the page shows it as stopped.
     */
    public function stop(string $run, ?string $stoppedBy = null): void
    {
        $state = (array) Cache::get($this->stateKey($run), []);
        $pid = (int) ($state['pid'] ?? 0);
        $command = $this->command($run);
        // The bracket keeps the pattern from matching the shell that runs pkill itself.
        $pattern = escapeshellarg('artisan '.substr($command, 0, -1).'['.substr($command, -1).']');

        $script = ($pid > 0 ? "pkill -TERM -P {$pid}; kill -TERM {$pid}; " : '')."pkill -TERM -f {$pattern}; true";

        try {
            Process::timeout(20)->run($script);
        } catch (Throwable $e) {
            throw new RuntimeException(trim($e->getMessage()), 0, $e);
        }

        if ($this->status($run)['exit_code'] !== null) {
            return; // it had already ended by itself; keep its real exit code
        }

        $path = $this->logPath($run);
        File::ensureDirectoryExists(dirname($path));
        File::append($path, sprintf("\nOprită%s la %s.\n%s143\n", $stoppedBy ? " de {$stoppedBy}" : '', now()->format('d.m.Y H:i'), self::SENTINEL));
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
        $log = $this->logPath(self::UPGRADE);

        File::ensureDirectoryExists(dirname($log));
        File::put($log, $output."\n".self::SENTINEL.$exit."\n");
        Cache::forever($this->stateKey(self::UPGRADE), [
            'started_at' => now()->toIso8601String(),
            'mode' => 'inline',
            'by' => $startedBy,
            'arguments' => [],
        ]);

        return ['output' => $output, 'exit_code' => $exit];
    }

    public function isRunning(string $run): bool
    {
        return $this->status($run)['running'];
    }

    /**
     * @return array{running: bool, stale: bool, mode: ?string, started_at: ?string, started_by: ?string, arguments: list<string>, pid: ?int, exit_code: ?int, log: string}
     */
    public function status(string $run): array
    {
        $state = (array) Cache::get($this->stateKey($run), []);
        $path = $this->logPath($run);
        $log = $this->readTail($path);
        $exit = preg_match('/'.preg_quote(self::SENTINEL, '/').'(\d+)\s*$/', $log, $matches) ? (int) $matches[1] : null;
        $startedAt = isset($state['started_at']) ? Carbon::parse($state['started_at']) : null;
        $stale = $startedAt !== null && $exit === null && $startedAt->lt(now()->subMinutes(self::STALE_MINUTES));

        return [
            'running' => $startedAt !== null && $exit === null && ! $stale,
            'stale' => $stale,
            'mode' => $state['mode'] ?? null,
            'started_at' => $startedAt?->toIso8601String(),
            'started_by' => $state['by'] ?? null,
            'arguments' => array_values((array) ($state['arguments'] ?? [])),
            'pid' => isset($state['pid']) ? (int) $state['pid'] : null,
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

    private function command(string $run): string
    {
        return self::COMMANDS[$run] ?? throw new InvalidArgumentException("Unknown run: {$run}");
    }

    private function stateKey(string $run): string
    {
        return "maintenance:{$this->command($run)}";
    }

    /**
     * The end of the log file. A run that floods its output must not be able
     * to pull its whole log into memory just because a page asks for its
     * state, so only the last stretch of the file is read.
     */
    private function readTail(string $path): string
    {
        if (! File::exists($path)) {
            return '';
        }

        $size = (int) File::size($path);

        if ($size <= self::LOG_BYTES) {
            return (string) File::get($path);
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        fseek($handle, -self::LOG_BYTES, SEEK_END);
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        // The first line is probably cut in half; drop it.
        $break = strpos($text, "\n");

        return $break === false ? $text : substr($text, $break + 1);
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
