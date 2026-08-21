<?php

namespace QuadCompanies\QuadSSO\Tests\Feature;

use Illuminate\Routing\Router;
use QuadCompanies\QuadSSO\Middleware\EnforceSessionRevocation;
use QuadCompanies\QuadSSO\Support\SessionRevoker;
use QuadCompanies\QuadSSO\Tests\Fixtures\User;
use QuadCompanies\QuadSSO\Tests\TestCase;

/**
 * Revocation that works when there is no server-side session to delete.
 *
 * Laravel Cloud defaults to the cookie driver, where the session lives entirely
 * in the client's cookie. Nothing can be deleted, so the only way to end such a
 * session is to reject it on its next request.
 */
class SessionRevocationTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->middleware('web')->group(function (Router $r) {
            $r->get('protected', fn() => 'secret')->name('protected');
        });
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'scim_external_id' => 'sub-ada', 'status' => 'active',
        ]);
    }

    // ---------------------------------------------------------------------
    // Middleware wiring
    // ---------------------------------------------------------------------

    public function test_middleware_is_registered_on_the_web_group(): void
    {
        $this->assertContains(
            EnforceSessionRevocation::class,
            $this->app['router']->getMiddlewareGroups()['web'] ?? []
        );
    }

    public function test_an_unrevoked_session_is_left_alone(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->withSession([EnforceSessionRevocation::SESSION_KEY => now()->getTimestamp()])
            ->get('/protected')
            ->assertOk()
            ->assertSee('secret');
    }

    public function test_a_session_older_than_the_revocation_is_rejected(): void
    {
        $user = $this->user();
        $user->quadsso_sessions_valid_after = now();
        $user->save();

        $this->actingAs($user)
            ->withSession([EnforceSessionRevocation::SESSION_KEY => now()->subHour()->getTimestamp()])
            ->get('/protected')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_a_session_newer_than_the_revocation_survives(): void
    {
        $user = $this->user();
        $user->quadsso_sessions_valid_after = now()->subHour();
        $user->save();

        $this->actingAs($user)
            ->withSession([EnforceSessionRevocation::SESSION_KEY => now()->getTimestamp()])
            ->get('/protected')
            ->assertOk();
    }

    /**
     * Sessions predating the upgrade, and any created by the host application's
     * own login, carry no establishment time. They must still be revocable.
     */
    public function test_a_session_with_no_recorded_time_is_revoked(): void
    {
        $user = $this->user();
        $user->quadsso_sessions_valid_after = now();
        $user->save();

        $this->actingAs($user)->get('/protected')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_nobody_is_logged_out_before_a_revocation_is_stamped(): void
    {
        $user = $this->user();

        $this->assertNull($user->quadsso_sessions_valid_after);

        $this->actingAs($user)->get('/protected')->assertOk();
    }

    public function test_api_requests_get_401_rather_than_a_redirect(): void
    {
        $user = $this->user();
        $user->quadsso_sessions_valid_after = now();
        $user->save();

        $this->actingAs($user)
            ->getJson('/protected')
            ->assertStatus(401)
            ->assertJson(['status' => 'error']);
    }

    public function test_revocation_can_be_switched_off(): void
    {
        config(['quadsso.sessions.revocation' => false]);

        $user = $this->user();
        $user->quadsso_sessions_valid_after = now();
        $user->save();

        $this->actingAs($user)->get('/protected')->assertOk();
    }

    // ---------------------------------------------------------------------
    // The revoker itself
    // ---------------------------------------------------------------------

    public function test_revoker_reports_honestly_on_a_stateless_driver(): void
    {
        config(['session.driver' => 'cookie']);

        $user = $this->user();
        $result = app(SessionRevoker::class)->revoke($user);

        $this->assertSame('cookie', $result['driver']);
        $this->assertNull($result['rows_deleted'], 'a count would imply rows exist');
        $this->assertTrue($result['revocation_stamped']);
        $this->assertTrue($result['effective'], 'the stamp is what ends the session here');
        $this->assertNotEmpty($result['notes']);
    }

    public function test_revoker_deletes_rows_on_a_database_driver(): void
    {
        $user = $this->user();
        $this->seedSession($user->id, 'sess-' . $user->id);

        $result = app(SessionRevoker::class)->revoke($user);

        $this->assertSame(1, $result['rows_deleted']);
        $this->assertTrue($result['effective']);
    }

    /**
     * The original bug: session.connection and session.table were ignored, so a
     * non-default connection or renamed table was never touched.
     */
    public function test_revoker_honours_a_custom_session_table(): void
    {
        \Illuminate\Support\Facades\Schema::create('user_sessions', function ($table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->longText('payload');
            $table->integer('last_activity');
        });

        config(['session.table' => 'user_sessions']);

        $user = $this->user();
        \Illuminate\Support\Facades\DB::table('user_sessions')->insert([
            'id' => 'x', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(),
        ]);

        $result = app(SessionRevoker::class)->revoke($user);

        $this->assertSame(1, $result['rows_deleted'], 'the configured table must be the one cleared');
    }

    public function test_revoker_reports_a_missing_revocation_column(): void
    {
        config(['quadsso.sessions.revoked_at_field' => 'no_such_column']);

        $user = $this->user();
        $result = app(SessionRevoker::class)->revoke($user);

        $this->assertFalse($result['revocation_stamped']);
        $this->assertNotEmpty($result['notes']);
    }

    public function test_password_cycling_is_opt_in(): void
    {
        $user = $this->user();
        $before = $user->password;

        app(SessionRevoker::class)->revoke($user);
        $this->assertSame($before, $user->fresh()->password);

        config(['quadsso.sessions.cycle_password_on_revoke' => true]);
        app(SessionRevoker::class)->revoke($user->fresh());

        $this->assertNotSame($before, $user->fresh()->password);
    }

    public function test_remember_token_is_always_cycled(): void
    {
        $user = $this->user();
        $user->setRememberToken('original');
        $user->save();

        app(SessionRevoker::class)->revoke($user);

        $this->assertNotSame('original', $user->fresh()->remember_token);
    }
}
