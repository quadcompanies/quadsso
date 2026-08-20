<?php

namespace QuadCompanies\QuadSSO\Tests\Feature;

use QuadCompanies\QuadSSO\Tests\Fixtures\NotAHooksClass;
use QuadCompanies\QuadSSO\Tests\Fixtures\RecordingHooks;
use QuadCompanies\QuadSSO\Tests\Fixtures\User;
use QuadCompanies\QuadSSO\Tests\TestCase;

/**
 * The management endpoint suspends and deletes accounts on presentation of a
 * shared secret, so the security properties matter more than the happy path:
 * it must be unreachable while disabled, refuse every request without the exact
 * key, and never act on a request it did not authenticate.
 */
class ManagementApiTest extends TestCase
{
    protected const KEY = 'test-management-key';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Read when routes are registered at boot, so it has to be set here.
        $app['config']->set('quadsso.management.enabled', true);
        $app['config']->set('quadsso.management.api_key', self::KEY);
        $app['config']->set('quadsso.management.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        RecordingHooks::reset();
    }

    private function makeUser(string $email = 'ada@example.test'): User
    {
        $user = User::create([
            'name' => 'Ada', 'email' => $email,
            'scim_external_id' => 'sub-' . md5($email), 'status' => 'active',
        ]);

        $this->seedSession($user->id, 'sess-' . $user->id);

        return $user;
    }

    private function manage(array $payload, ?string $key = self::KEY, string $header = 'X-QuadSSO-Key')
    {
        return $this->postJson('/api/quadsso-mgr', $payload, $key === null ? [] : [$header => $key]);
    }

    // ---------------------------------------------------------------------
    // Authentication
    // ---------------------------------------------------------------------

    public function test_rejects_a_request_with_no_key(): void
    {
        $user = $this->makeUser();

        $this->manage(['action' => 'SUSPEND', 'email' => $user->email], key: null)
            ->assertStatus(401);

        $this->assertSame('active', $user->fresh()->status);
        $this->assertSame(1, $this->sessionCountFor($user->id));
    }

    public function test_rejects_a_request_with_the_wrong_key(): void
    {
        $user = $this->makeUser();

        $this->manage(['action' => 'SUSPEND', 'email' => $user->email], key: 'not-the-key')
            ->assertStatus(401);

        $this->assertSame('active', $user->fresh()->status);
    }

    /**
     * A prefix of the real key must not pass — the comparison has to be on the
     * whole value, not a truncated or loose one.
     */
    public function test_rejects_a_partial_key(): void
    {
        $user = $this->makeUser();

        $this->manage(['action' => 'DELETE', 'email' => $user->email], key: substr(self::KEY, 0, 8))
            ->assertStatus(401);

        $this->assertNotNull($user->fresh());
    }

    public function test_accepts_the_key_as_a_bearer_token(): void
    {
        $user = $this->makeUser();

        $this->postJson(
            '/api/quadsso-mgr',
            ['action' => 'SUSPEND', 'email' => $user->email],
            ['Authorization' => 'Bearer ' . self::KEY]
        )->assertOk();

        $this->assertSame('blocked', $user->fresh()->status);
    }

    public function test_fails_closed_when_no_key_is_configured(): void
    {
        config(['quadsso.management.api_key' => null]);

        $user = $this->makeUser();

        $this->manage(['action' => 'SUSPEND', 'email' => $user->email], key: null)
            ->assertStatus(503);

        $this->assertSame('active', $user->fresh()->status);
    }

    public function test_endpoint_only_accepts_post(): void
    {
        $this->getJson('/api/quadsso-mgr')->assertStatus(405);
    }

    // ---------------------------------------------------------------------
    // Route composition
    // ---------------------------------------------------------------------

    public function test_route_is_guarded_by_the_api_key_middleware(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('quadsso.management');

        $this->assertNotNull($route);
        $this->assertContains(
            \QuadCompanies\QuadSSO\Middleware\ManagementApiKey::class,
            $route->gatherMiddleware()
        );
    }

    /**
     * Server-to-server, like SLO: no session, no CSRF token. Inside the web
     * group every call would be rejected with HTTP 419.
     */
    public function test_route_is_outside_the_web_group(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('quadsso.management');

        $this->assertNotContains('web', $route->gatherMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());
    }

    public function test_path_is_configurable(): void
    {
        $this->assertSame('api/quadsso-mgr', config('quadsso.management.path'));
    }

    // ---------------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------------

    public function test_rejects_an_unknown_action(): void
    {
        $user = $this->makeUser();

        $this->manage(['action' => 'DESTROY', 'email' => $user->email])->assertStatus(422);

        $this->assertSame('active', $user->fresh()->status);
    }

    public function test_requires_an_action_and_an_email(): void
    {
        $this->manage([])->assertStatus(422);
        $this->manage(['action' => 'SUSPEND'])->assertStatus(422);
        $this->manage(['email' => 'ada@example.test'])->assertStatus(422);
    }

    public function test_rejects_a_malformed_email(): void
    {
        $this->manage(['action' => 'SUSPEND', 'email' => 'not-an-email'])->assertStatus(422);
    }

    public function test_returns_404_for_an_unknown_user(): void
    {
        $this->manage(['action' => 'SUSPEND', 'email' => 'nobody@example.test'])->assertStatus(404);
    }

    public function test_action_is_case_insensitive(): void
    {
        $user = $this->makeUser();

        $this->manage(['action' => 'suspend', 'email' => $user->email])->assertOk();

        $this->assertSame('blocked', $user->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // SUSPEND
    // ---------------------------------------------------------------------

    public function test_suspend_blocks_the_account_and_clears_sessions(): void
    {
        $user = $this->makeUser();
        $user->setRememberToken('original-token');
        $user->save();

        $this->manage(['action' => 'SUSPEND', 'email' => $user->email])
            ->assertOk()
            ->assertJson(['status' => 'ok', 'action' => 'SUSPEND', 'sessions_cleared' => 1]);

        $fresh = $user->fresh();

        $this->assertSame('blocked', $fresh->status);
        $this->assertSame(0, $this->sessionCountFor($user->id));
        $this->assertNotSame('original-token', $fresh->remember_token, 'remember-me must not survive suspension');
    }

    public function test_suspend_leaves_the_row_in_place(): void
    {
        $user = $this->makeUser();

        $this->manage(['action' => 'SUSPEND', 'email' => $user->email])->assertOk();

        $this->assertNotNull(User::find($user->id));
    }

    public function test_suspend_only_touches_the_named_user(): void
    {
        $target = $this->makeUser('target@example.test');
        $other = $this->makeUser('other@example.test');

        $this->manage(['action' => 'SUSPEND', 'email' => $target->email])->assertOk();

        $this->assertSame('blocked', $target->fresh()->status);
        $this->assertSame('active', $other->fresh()->status);
        $this->assertSame(1, $this->sessionCountFor($other->id));
    }

    public function test_a_suspended_user_cannot_log_back_in(): void
    {
        $user = $this->makeUser();

        $this->manage(['action' => 'SUSPEND', 'email' => $user->email])->assertOk();

        $this->assertSame(
            config('quadsso.provisioning.blocked_status_value'),
            $user->fresh()->status,
            'the status the SSO callback refuses on'
        );
    }

    // ---------------------------------------------------------------------
    // DELETE
    // ---------------------------------------------------------------------

    public function test_delete_removes_the_user_and_clears_sessions(): void
    {
        $user = $this->makeUser();

        $this->manage(['action' => 'DELETE', 'email' => $user->email])
            ->assertOk()
            ->assertJson(['status' => 'ok', 'action' => 'DELETE', 'sessions_cleared' => 1]);

        $this->assertNull(User::find($user->id));
        $this->assertSame(0, $this->sessionCountFor($user->id));
    }

    public function test_delete_only_removes_the_named_user(): void
    {
        $target = $this->makeUser('target@example.test');
        $other = $this->makeUser('other@example.test');

        $this->manage(['action' => 'DELETE', 'email' => $target->email])->assertOk();

        $this->assertNull(User::find($target->id));
        $this->assertNotNull(User::find($other->id));
    }

    // ---------------------------------------------------------------------
    // Lifecycle hooks
    // ---------------------------------------------------------------------

    public function test_no_hooks_configured_is_fine(): void
    {
        config(['quadsso.management.hooks' => null]);

        $user = $this->makeUser();

        $this->manage(['action' => 'SUSPEND', 'email' => $user->email])
            ->assertOk()
            ->assertJson(['post_hook_failed' => false]);
    }

    public function test_suspend_hooks_run_before_and_after_in_order(): void
    {
        config(['quadsso.management.hooks' => RecordingHooks::class]);

        $user = $this->makeUser();

        $this->manage(['action' => 'SUSPEND', 'email' => $user->email])->assertOk();

        $this->assertSame(['beforeSuspend', 'afterSuspend'], RecordingHooks::$calls);
    }

    /**
     * Ordering has to be observable, not just asserted by call sequence: the
     * before hook must see the account still active, the after hook must see it
     * blocked.
     */
    public function test_suspend_hooks_observe_the_state_around_the_change(): void
    {
        config(['quadsso.management.hooks' => RecordingHooks::class]);

        $user = $this->makeUser();

        $this->manage(['action' => 'SUSPEND', 'email' => $user->email])->assertOk();

        $this->assertSame('active', RecordingHooks::$statusAtCall['beforeSuspend']);
        $this->assertSame('blocked', RecordingHooks::$statusAtCall['afterSuspend']);
    }

    public function test_delete_hooks_run_before_and_after_in_order(): void
    {
        config(['quadsso.management.hooks' => RecordingHooks::class]);

        $user = $this->makeUser();

        $this->manage(['action' => 'DELETE', 'email' => $user->email])->assertOk();

        $this->assertSame(['beforeDelete', 'afterDelete'], RecordingHooks::$calls);
    }

    /**
     * The whole point of a pre-delete hook is to act while the row is still
     * there; the post hook must run once it is gone.
     */
    public function test_delete_hooks_straddle_the_row_actually_disappearing(): void
    {
        config(['quadsso.management.hooks' => RecordingHooks::class]);

        $user = $this->makeUser();

        $this->manage(['action' => 'DELETE', 'email' => $user->email])->assertOk();

        $this->assertTrue(RecordingHooks::$existedAtCall['beforeDelete'], 'row must still exist pre-delete');
        $this->assertFalse(RecordingHooks::$existedAtCall['afterDelete'], 'row must be gone post-delete');
    }

    public function test_suspend_does_not_run_delete_hooks(): void
    {
        config(['quadsso.management.hooks' => RecordingHooks::class]);

        $user = $this->makeUser();

        $this->manage(['action' => 'SUSPEND', 'email' => $user->email])->assertOk();

        $this->assertNotContains('beforeDelete', RecordingHooks::$calls);
        $this->assertNotContains('afterDelete', RecordingHooks::$calls);
    }

    // ---------------------------------------------------------------------
    // Hook veto and failure
    // ---------------------------------------------------------------------

    public function test_a_throwing_before_suspend_hook_vetoes_the_operation(): void
    {
        config(['quadsso.management.hooks' => RecordingHooks::class]);
        RecordingHooks::$throwFrom = ['beforeSuspend'];

        $user = $this->makeUser();

        $this->manage(['action' => 'SUSPEND', 'email' => $user->email])->assertStatus(409);

        $this->assertSame('active', $user->fresh()->status, 'nothing may be written when vetoed');
        $this->assertSame(1, $this->sessionCountFor($user->id));
        $this->assertNotContains('afterSuspend', RecordingHooks::$calls);
    }

    public function test_a_throwing_before_delete_hook_leaves_the_user_intact(): void
    {
        config(['quadsso.management.hooks' => RecordingHooks::class]);
        RecordingHooks::$throwFrom = ['beforeDelete'];

        $user = $this->makeUser();

        $this->manage(['action' => 'DELETE', 'email' => $user->email])->assertStatus(409);

        $this->assertNotNull(User::find($user->id));
        $this->assertSame('active', $user->fresh()->status);
        $this->assertNotContains('afterDelete', RecordingHooks::$calls);
    }

    /**
     * A post hook cannot undo a committed change, so the action stands and the
     * caller is told the follow-up failed — rather than being handed an error
     * that invites retrying an action that already happened.
     */
    public function test_a_throwing_after_hook_does_not_undo_the_action(): void
    {
        config(['quadsso.management.hooks' => RecordingHooks::class]);
        RecordingHooks::$throwFrom = ['afterDelete'];

        $user = $this->makeUser();

        $this->manage(['action' => 'DELETE', 'email' => $user->email])
            ->assertOk()
            ->assertJson(['status' => 'ok', 'post_hook_failed' => true]);

        $this->assertNull(User::find($user->id), 'the deletion still stands');
    }

    public function test_a_hooks_class_of_the_wrong_type_is_refused(): void
    {
        config(['quadsso.management.hooks' => NotAHooksClass::class]);

        $user = $this->makeUser();

        $this->manage(['action' => 'DELETE', 'email' => $user->email])->assertStatus(500);

        $this->assertNotNull(User::find($user->id), 'nothing may happen if hooks cannot be resolved');
    }
}
