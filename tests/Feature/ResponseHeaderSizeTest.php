<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

/**
 * nginx reads a FastCGI response header into a buffer of a few kilobytes and
 * answers 502 when it does not fit — without PHP hearing about it, so nothing
 * reaches the application log and only the heaviest pages are affected. The
 * root view hands @vite the page component, which used to put every chunk
 * that page imports into a Link header, and the cash flow report grew past
 * the line. These pages keep their headers small enough that nginx never has
 * to make that call.
 */
uses(RefreshDatabase::class);

/** What nginx holds by default; the response header has to fit inside it. */
const FASTCGI_BUFFER = 4096;

function headerBytes(TestResponse $response): int
{
    $bytes = 0;

    foreach ($response->headers->allPreserveCase() as $name => $values) {
        foreach ($values as $value) {
            $bytes += strlen($name) + strlen((string) $value) + 4;
        }
    }

    return $bytes;
}

test('a page answers with a header small enough for the gateway to pass on', function (string $path) {
    $response = $this->actingAs(User::factory()->create())->get($path);

    expect($response->status())->toBeLessThan(500)
        // Half the buffer, so the cookies and whatever a future header adds
        // still leave room.
        ->and(headerBytes($response))->toBeLessThan(FASTCGI_BUFFER / 2)
        ->and($response->headers->has('Link'))->toBeFalse();
})->with([
    '/dashboard',
    '/reports/cash-flow',
    '/partners',
]);
