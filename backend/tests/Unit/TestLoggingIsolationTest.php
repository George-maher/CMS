<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * F-12 — running the test suite must never append to storage/logs/laravel.log.
 *
 * The file is git-ignored but it lives in the working tree, and it had grown to
 * ~32 MB purely from suite runs, which also made every run's log output
 * indistinguishable from real application events. `phpunit.xml` now points
 * LOG_CHANNEL at the existing `stderr` channel.
 *
 * Note the trap this test guards: the obvious `LOG_CHANNEL=null` does NOT work.
 * Illuminate\Support\Env::getOption() converts the bare word `null` to PHP
 * null, so `config('logging.default')` becomes null, LogManager cannot build
 * the configured logger ("Log [] is not defined") and Laravel silently falls
 * back to the EMERGENCY logger — which writes to this very file.
 *
 * The assertion is on the OUTCOME (the file does not grow), not on the
 * configured channel name, so it keeps protecting the invariant if the channel
 * is renamed or the configuration is reorganised.
 */
class TestLoggingIsolationTest extends TestCase
{
    public function test_logging_inside_a_test_never_touches_the_application_log(): void
    {
        $path = storage_path('logs/laravel.log');

        clearstatcache(true, $path);
        $sizeBefore = file_exists($path) ? (int) filesize($path) : -1;
        $mtimeBefore = file_exists($path) ? (int) filemtime($path) : -1;

        // Every severity, including the level the emergency fallback is used at.
        Log::debug('f-12 log isolation probe');
        Log::warning('f-12 log isolation probe');
        Log::error('f-12 log isolation probe');
        Log::emergency('f-12 log isolation probe');

        clearstatcache(true, $path);
        $sizeAfter = file_exists($path) ? (int) filesize($path) : -1;
        $mtimeAfter = file_exists($path) ? (int) filemtime($path) : -1;

        $this->assertSame(
            $sizeBefore,
            $sizeAfter,
            'A log call inside a test appended to storage/logs/laravel.log: the test environment is writing to the application log.'
        );

        $this->assertSame(
            $mtimeBefore,
            $mtimeAfter,
            'A log call inside a test touched storage/logs/laravel.log.'
        );
    }
}
