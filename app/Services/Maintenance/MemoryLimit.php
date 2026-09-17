<?php

namespace App\Services\Maintenance;

/**
 * PHP's memory limit, raised by the application itself.
 *
 * The servers this runs on are managed by someone else and nobody here can
 * edit their ini files, so a request that needs more than the pool's default
 * asks for it while booting: `memory_limit` may be changed at runtime, and a
 * request that runs out of it dies with a gateway error that explains nothing.
 */
class MemoryLimit
{
    /** No limit at all, the way PHP spells it. */
    private const UNLIMITED = -1;

    /**
     * Raise the limit to `$wanted` when the server gives us less, and leave a
     * more generous setting (an unlimited CLI, a `php -d` on the command line)
     * exactly as it is. Returns the limit in force afterwards.
     */
    public function raiseTo(?string $wanted): string
    {
        $target = $this->bytes((string) $wanted);
        $current = $this->bytes((string) ini_get('memory_limit'));

        if ($target > 0 && $current !== self::UNLIMITED && $current < $target) {
            ini_set('memory_limit', trim((string) $wanted));
        }

        return (string) ini_get('memory_limit');
    }

    /**
     * An ini size — `256M`, `1G`, `134217728` — in bytes. Unlimited reads as
     * -1, and anything unreadable as 0 so it never wins a comparison.
     */
    public function bytes(string $value): int
    {
        if (! preg_match('/^(-?\d+)\s*([kmg])?b?$/i', trim($value), $matches)) {
            return 0;
        }

        $size = (int) $matches[1];

        if ($size < 0) {
            return self::UNLIMITED;
        }

        return match (strtolower($matches[2] ?? '')) {
            'g' => $size * 1024 ** 3,
            'm' => $size * 1024 ** 2,
            'k' => $size * 1024,
            default => $size,
        };
    }
}
