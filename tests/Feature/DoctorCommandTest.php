<?php

namespace QuadCompanies\QuadSSO\Tests\Feature;

use QuadCompanies\QuadSSO\Tests\TestCase;

/**
 * The command exists so a support round-trip is one short instruction rather
 * than a crafted tinker one-liner pasted into a hosting console. These tests
 * pin the failure modes it is meant to surface — the ones that otherwise go
 * unnoticed until someone cannot log in.
 */
class DoctorCommandTest extends TestCase
{
    private function report(): array
    {
        \Illuminate\Support\Facades\Artisan::call('quadsso:doctor', ['--json' => true]);

        return json_decode(\Illuminate\Support\Facades\Artisan::output(), true) ?? [];
    }

    private function check(array $report, string $check): ?array
    {
        foreach ($report['checks'] ?? [] as $row) {
            if ($row['check'] === $check) {
                return $row;
            }
        }

        return null;
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('quadsso:doctor')->assertSuccessful();
    }

    public function test_json_output_is_machine_readable(): void
    {
        $report = $this->report();

        $this->assertArrayHasKey('ok', $report);
        $this->assertArrayHasKey('checks', $report);
        $this->assertNotEmpty($report['checks']);
    }

    public function test_a_healthy_install_passes(): void
    {
        $report = $this->report();

        $this->assertTrue($report['ok'], 'the test environment should be a healthy install');
        $this->assertSame(0, $report['failures']);
    }

    public function test_reports_the_session_driver(): void
    {
        $report = $this->report();

        $this->assertSame('database', $this->check($report, 'session.driver')['detail']);
    }

    /**
     * The finding that took a support round-trip to reach.
     */
    public function test_warns_that_a_cookie_driver_keeps_no_session_record(): void
    {
        config(['session.driver' => 'cookie']);

        $row = $this->check($this->report(), 'session.driver');

        $this->assertSame('WARN', $row['status']);
        $this->assertStringContainsString('no server-side session record', $row['detail']);
    }

    public function test_confirms_the_revocation_column_and_middleware(): void
    {
        $report = $this->report();

        $this->assertSame('PASS', $this->check($report, 'revocation column')['status']);
        $this->assertSame('PASS', $this->check($report, 'revocation middleware')['status']);
    }

    /**
     * On a stateless driver the revocation column is the only thing that can
     * end a session, so its absence is fatal rather than advisory.
     */
    public function test_a_missing_revocation_column_is_fatal_on_a_stateless_driver(): void
    {
        config([
            'session.driver' => 'cookie',
            'quadsso.sessions.revoked_at_field' => 'no_such_column',
        ]);

        $report = $this->report();

        $this->assertSame('FAIL', $this->check($report, 'revocation column')['status']);
        $this->assertFalse($report['ok']);
    }

    public function test_a_missing_status_column_is_reported_as_fatal(): void
    {
        config(['quadsso.provisioning.user_status_field' => 'no_such_column']);

        $row = $this->check($this->report(), 'provisioning.user_status_field');

        $this->assertSame('FAIL', $row['status']);
        $this->assertStringContainsString('all logins will be refused', $row['detail']);
    }

    public function test_reports_missing_authentik_configuration(): void
    {
        config(['quadsso.authentik.client_id' => null]);

        $report = $this->report();

        $this->assertSame('FAIL', $this->check($report, 'AUTHENTIK_CLIENT_ID')['status']);
        $this->assertFalse($report['ok']);
    }

    // ---------------------------------------------------------------------
    // Local auth lockout
    // ---------------------------------------------------------------------

    public function test_reports_the_lockout_as_enabled_by_default(): void
    {
        $row = $this->check($this->report(), 'lockout');

        $this->assertSame('PASS', $row['status']);
        $this->assertStringContainsString('enabled', $row['detail']);
    }

    public function test_confirms_the_lockout_middleware_is_registered(): void
    {
        $this->assertSame('PASS', $this->check($this->report(), 'lockout middleware')['status']);
    }

    /**
     * Switching the lockout off is legitimate for a mixed-auth application, but
     * leaving reachable reset routes behind is the SSO bypass — so it is
     * reported, and reported against the real route table.
     */
    public function test_warns_when_the_lockout_is_off_and_reset_routes_exist(): void
    {
        config(['quadsso.disable_local_auth.enabled' => false]);

        \Illuminate\Support\Facades\Route::middleware('web')
            ->get('forgot-password', fn() => 'form')->name('password.request');

        $row = $this->check($this->report(), 'lockout');

        $this->assertSame('WARN', $row['status']);
        $this->assertStringContainsString('password reset bypasses SSO', $row['detail']);
    }

    /**
     * No such routes means no bypass, so there is nothing to warn about.
     */
    public function test_does_not_nag_when_there_are_no_local_auth_routes(): void
    {
        config(['quadsso.disable_local_auth.enabled' => false]);

        $row = $this->check($this->report(), 'lockout');

        $this->assertSame('PASS', $row['status']);
        $this->assertStringContainsString('no local auth routes', $row['detail']);
    }

    public function test_warns_when_break_glass_login_is_blocked(): void
    {
        config(['quadsso.disable_local_auth.route_names' => ['login', 'register']]);

        $row = $this->check($this->report(), 'break-glass login');

        $this->assertSame('WARN', $row['status']);
    }

    public function test_no_break_glass_warning_with_the_default_route_list(): void
    {
        $this->assertNull($this->check($this->report(), 'break-glass login'));
    }

    public function test_reports_the_management_api_as_disabled_by_default(): void
    {
        $row = $this->check($this->report(), 'endpoint');

        $this->assertSame('PASS', $row['status']);
        $this->assertStringContainsString('disabled', $row['detail']);
    }

    public function test_confirms_slo_stays_outside_the_web_group(): void
    {
        $this->assertSame('PASS', $this->check($this->report(), 'sso.logout outside web')['status']);
    }

    public function test_exit_code_reflects_failures(): void
    {
        $this->artisan('quadsso:doctor')->assertSuccessful();

        config(['quadsso.authentik.base_url' => null]);

        $this->artisan('quadsso:doctor')->assertFailed();
    }
}
