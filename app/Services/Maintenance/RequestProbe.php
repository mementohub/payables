<?php

namespace App\Services\Maintenance;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * A breadcrumb per request, written straight to disk.
 *
 * A request that runs out of memory writes itself to the application log; a
 * request whose worker is killed writes nothing at all, and the gateway error
 * the browser gets says only that the server hung up. Here one line goes down
 * before the work starts and one after it ends, unbuffered, so a request that
 * never came back is the one with no second line — which is the difference
 * between PHP giving up and something outside PHP taking the process away.
 */
class RequestProbe
{
    /** How many lines are kept; older ones are dropped as the file is trimmed. */
    private const LINES = 200;

    /** The file is trimmed once it grows past this. */
    private const BYTES = 131072;

    public function __construct(private ?string $directory = null) {}

    public function path(): string
    {
        return ($this->directory ?? storage_path('logs')).'/requests.log';
    }

    /** The markers are ASCII, so reading the file back never depends on encoding. */
    public function started(string $id, string $method, string $path): void
    {
        $this->write(sprintf('%s %s > %s %s', $this->now(), $id, $method, $path));
    }

    public function finished(string $id, string $method, string $path, int $status, float $seconds): void
    {
        $this->write(sprintf(
            '%s %s < %d %s %s %s %s',
            $this->now(),
            $id,
            $status,
            $this->duration($seconds),
            $this->peak(),
            $method,
            $path,
        ));
    }

    /**
     * The last requests, newest first, each with the id that pairs its two
     * lines and whether the second one ever arrived.
     *
     * @return array{entries: list<array{at: ?string, id: string, line: string, unfinished: bool}>}
     */
    public function tail(): array
    {
        $lines = $this->read();
        $finished = [];

        foreach ($lines as $line) {
            if (preg_match('/^\S+ (\S+) </', $line, $matches)) {
                $finished[$matches[1]] = true;
            }
        }

        $entries = [];

        foreach (array_reverse($lines) as $line) {
            if (! preg_match('/^(\S+) (\S+) ([<>])(.*)$/', $line, $matches)) {
                continue;
            }

            // The opening line of a request that came back says nothing the
            // closing one does not; only a request still missing its answer
            // is worth a row of its own.
            if ($matches[3] === '>' && isset($finished[$matches[2]])) {
                continue;
            }

            $entries[] = [
                'at' => $this->date($matches[1]),
                'id' => $matches[2],
                'line' => trim($matches[4]),
                'unfinished' => $matches[3] === '>',
            ];
        }

        return ['entries' => array_slice($entries, 0, 40)];
    }

    private function write(string $line): void
    {
        try {
            $path = $this->path();
            File::ensureDirectoryExists(dirname($path));

            if (File::exists($path) && File::size($path) > self::BYTES) {
                File::put($path, implode("\n", array_slice($this->read(), -self::LINES))."\n");
            }

            // Appended unbuffered: whatever happens to this process next, the
            // line is already on disk.
            file_put_contents($path, $line."\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Tracing must never be the reason a request fails.
        }
    }

    /**
     * @return list<string>
     */
    private function read(): array
    {
        $path = $this->path();

        if (! File::exists($path)) {
            return [];
        }

        $text = (string) File::get($path);

        return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text) ?: [])));
    }

    private function now(): string
    {
        return Carbon::now()->toIso8601String();
    }

    private function duration(float $seconds): string
    {
        return $seconds >= 1 ? round($seconds, 1).'s' : round($seconds * 1000).'ms';
    }

    private function peak(): string
    {
        return round(memory_get_peak_usage(true) / 1048576, 1).'MB';
    }

    private function date(string $value): ?string
    {
        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}
