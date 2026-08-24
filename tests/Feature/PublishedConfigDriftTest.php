<?php

namespace QuadCompanies\QuadSSO\Tests\Feature;

use QuadCompanies\QuadSSO\QuadSSOServiceProvider;
use QuadCompanies\QuadSSO\Tests\TestCase;

/**
 * Stands in for an application that published config/quadsso.php at an earlier
 * version and never re-published.
 *
 * mergeConfigFrom() merges top-level keys only, so that file supplies the whole
 * 'logging' array and any key the package adds afterwards reads back as null —
 * silently. A debugging switch set in .env then does nothing at all, which is
 * exactly how QUADSSO_LOG_SESSION_WATCHDOG failed to produce a single line.
 */
class PublishedConfigDriftTest extends TestCase
{
    /**
     * In a real application the config files are loaded first and the provider
     * registers afterwards. Testbench inverts that — defineEnvironment() runs
     * after registration — so the provider is re-registered here to put the two
     * back in the order production uses.
     */
    private function withPublishedConfig(array $groups): void
    {
        foreach ($groups as $key => $value) {
            config(["quadsso.{$key}" => $value]);
        }

        (new QuadSSOServiceProvider($this->app))->register();
    }

    /** A published file frozen before 2.5.0: no session_watchdog key. */
    private function loggingBefore250(): array
    {
        return [
            'enabled'    => false,
            'channel'    => null,
            'sso_events' => true,
            'slo_events' => true,
            'api_events' => false,
        ];
    }

    public function test_a_key_added_after_publishing_still_gets_its_default(): void
    {
        $this->withPublishedConfig(['logging' => $this->loggingBefore250()]);

        $this->assertNotNull(config('quadsso.logging.session_watchdog'));
        $this->assertFalse(config('quadsso.logging.session_watchdog'));
    }

    public function test_values_the_application_defined_are_left_alone(): void
    {
        $this->withPublishedConfig(['logging' => $this->loggingBefore250()]);

        $this->assertTrue(config('quadsso.logging.sso_events'));
        $this->assertFalse(config('quadsso.logging.api_events'));
    }

    /**
     * The reason this is not array_replace_recursive(). Merging lists index by
     * index would hand back the package's remaining six route names and change
     * which routes are blocked without anyone asking.
     */
    public function test_a_trimmed_list_is_not_topped_up_from_the_defaults(): void
    {
        $this->withPublishedConfig([
            'disable_local_auth' => [
                'enabled'     => true,
                'response'    => 'redirect',
                'redirect_to' => '/auth/sso',
                'route_names' => ['register'],
                'paths'       => ['register'],
            ],
        ]);

        $this->assertSame(['register'], config('quadsso.disable_local_auth.route_names'));
        $this->assertSame(['register'], config('quadsso.disable_local_auth.paths'));
    }

    public function test_a_group_missing_entirely_is_supplied_whole(): void
    {
        $this->withPublishedConfig(['logging' => $this->loggingBefore250()]);

        $this->assertSame('quadsso', config('quadsso.ui.error_bag'));
        $this->assertSame(
            'quadsso_sessions_valid_after',
            config('quadsso.sessions.revoked_at_field')
        );
    }

    /**
     * A published value of false must survive. Filling only genuinely absent
     * keys is the difference between a default and an override.
     */
    public function test_a_falsy_value_is_not_mistaken_for_an_absent_one(): void
    {
        $this->withPublishedConfig([
            'sso' => ['enable_slo' => false, 'redirect_after_login' => '/dashboard'],
        ]);

        $this->assertFalse(config('quadsso.sso.enable_slo'));
        $this->assertSame('/dashboard', config('quadsso.sso.redirect_after_login'));
        // Absent from the published group, so the package default applies.
        $this->assertFalse(config('quadsso.sso.enable_jit_provisioning'));
    }
}
