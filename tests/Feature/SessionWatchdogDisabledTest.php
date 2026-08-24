<?php

namespace QuadCompanies\QuadSSO\Tests\Feature;

use Illuminate\Support\Facades\Log;
use QuadCompanies\QuadSSO\Tests\TestCase;

/**
 * A line per request is unreadable in production, so the watchdog stays off
 * until somebody is actively reproducing a fault.
 */
class SessionWatchdogDisabledTest extends TestCase
{
    public function test_it_is_off_by_default(): void
    {
        $this->assertFalse(config('quadsso.logging.session_watchdog'));
    }

    public function test_nothing_is_observed_while_it_is_off(): void
    {
        config(['quadsso.logging.sso_events' => true]);

        Log::spy();
        $this->get('/auth/sso/callback');

        Log::shouldNotHaveReceived('info', [
            \Mockery::on(fn($message) => str_contains($message, 'session state observed')),
            \Mockery::any(),
        ]);
    }
}
