<?php

namespace App\Services\Maintenance;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * The tail of the application log, for an installation nobody can reach over
 * SSH: when a page answers with a gateway error, this is the only way to see
 * what the server actually said. Read only, and only ever the end of the
 * file, so a log that has grown to gigabytes still costs nothing to look at.
 */
class ApplicationLog
{
    /** How much of the end of the file is read into memory. */
    private const BYTES = 262144;

    /** How many entries the page shows. */
    private const ENTRIES = 25;

    /** How much of one entry is kept, so a stack trace cannot flood the page. */
    private const ENTRY_CHARS = 4000;

    public function __construct(private ?string $directory = null) {}

    public function directory(): string
    {
        return $this->directory ?? storage_path('logs');
    }

    /**
     * The newest laravel log file, whether the channel writes one file or one
     * per day.
     */
    public function path(): ?string
    {
        $files = collect(File::glob($this->directory().'/laravel*.log'))
            ->sortByDesc(fn (string $file) => File::lastModified($file));

        return $files->first();
    }

    /**
     * The last entries, newest first.
     *
     * @return array{path: ?string, size: int, written_at: ?string, entries: list<array{at: ?string, level: string, message: string, body: string}>}
     */
    public function tail(): array
    {
        $path = $this->path();

        if ($path === null) {
            return ['path' => null, 'size' => 0, 'written_at' => null, 'entries' => []];
        }

        $entries = [];

        foreach ($this->split($this->read($path)) as $entry) {
            $entries[] = $this->parse($entry);
        }

        return [
            'path' => basename($path),
            'size' => (int) File::size($path),
            'written_at' => Carbon::createFromTimestamp(File::lastModified($path))->toIso8601String(),
            'entries' => array_slice(array_reverse($entries), 0, self::ENTRIES),
        ];
    }

    private function read(string $path): string
    {
        $size = (int) File::size($path);

        if ($size <= self::BYTES) {
            return (string) File::get($path);
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        fseek($handle, -self::BYTES, SEEK_END);
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        return $text;
    }

    /**
     * Split on the timestamp every entry starts with, so a stack trace stays
     * with the line that raised it.
     *
     * @return list<string>
     */
    private function split(string $text): array
    {
        $parts = preg_split('/^(?=\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/m', trim($text)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn (string $part) => $part !== ''));
    }

    /**
     * @return array{at: ?string, level: string, message: string, body: string}
     */
    private function parse(string $entry): array
    {
        $head = strtok($entry, "\n") ?: $entry;
        preg_match('/^\[([^\]]+)\]\s*(?:[\w-]+)\.(\w+):\s*(.*)$/s', $head, $matches);

        return [
            'at' => isset($matches[1]) ? $this->date($matches[1]) : null,
            'level' => strtolower($matches[2] ?? 'info'),
            'message' => trim($matches[3] ?? $head),
            'body' => mb_substr($entry, 0, self::ENTRY_CHARS),
        ];
    }

    private function date(string $value): ?string
    {
        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
