<?php

namespace QuadCompanies\QuadSSO\Tests\Feature;

use QuadCompanies\QuadSSO\Tests\Concerns\MocksSocialite;
use QuadCompanies\QuadSSO\Tests\Fixtures\BlockableUser;
use QuadCompanies\QuadSSO\Tests\Fixtures\User;
use QuadCompanies\QuadSSO\Tests\TestCase;

/**
 * Guards the authorization decisions the SSO callback makes: which local row a
 * given IdP identity is allowed to become, and who is allowed in at all.
 *
 * Every test here is an attempt to get approved as somebody you are not, or to
 * get approved when no approval was granted.
 */
class IdentityResolutionTest extends TestCase
{
    use MocksSocialite;

    private function hitCallback()
    {
        return $this->get('/auth/sso/callback');
    }

    // ---------------------------------------------------------------------
    // The authoritative path
    // ---------------------------------------------------------------------

    public function test_resolves_user_by_external_id(): void
    {
        $user = User::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'scim_external_id' => 'sub-ada', 'status' => 'active',
        ]);

        $this->fakeIdpUser(sub: 'sub-ada', email: 'ada@example.test');

        $this->hitCallback()->assertRedirect('/home');
        $this->assertAuthenticatedAs($user->fresh());
    }

    /**
     * The core anti-takeover property: when the sub matches one row and the
     * email matches a different row, the sub must win. Losing this means anyone
     * who can set their IdP email to a victim's address inherits their account.
     */
    public function test_email_never_overrides_a_matching_external_id(): void
    {
        $victim = User::create([
            'name' => 'Victim', 'email' => 'victim@example.test',
            'scim_external_id' => 'sub-victim', 'status' => 'active',
        ]);

        $attacker = User::create([
            'name' => 'Attacker', 'email' => 'attacker@example.test',
            'scim_external_id' => 'sub-attacker', 'status' => 'active',
        ]);

        // Attacker's IdP account now claims the victim's email address.
        $this->fakeIdpUser(sub: 'sub-attacker', email: 'victim@example.test');

        $this->hitCallback();

        $this->assertAuthenticatedAs($attacker->fresh());
        $this->assertNotSame($victim->id, auth()->id());
    }

    // ---------------------------------------------------------------------
    // Legacy email binding
    // ---------------------------------------------------------------------

    public function test_binds_external_id_onto_unbound_row_when_enabled(): void
    {
        config(['quadsso.sso.allow_legacy_email_binding' => true]);

        $user = User::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'scim_external_id' => null, 'status' => 'active',
        ]);

        $this->fakeIdpUser(sub: 'sub-ada', email: 'ada@example.test', emailVerified: true);

        $this->hitCallback()->assertRedirect('/home');

        $this->assertSame('sub-ada', $user->fresh()->scim_external_id);
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_refuses_to_bind_when_idp_says_email_is_unverified(): void
    {
        config(['quadsso.sso.allow_legacy_email_binding' => true]);

        $user = User::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'scim_external_id' => null, 'status' => 'active',
        ]);

        $this->fakeIdpUser(sub: 'sub-ada', email: 'ada@example.test', emailVerified: false);

        $this->hitCallback()->assertRedirect('/login');

        $this->assertNull($user->fresh()->scim_external_id);
        $this->assertGuest();
    }

    /**
     * Binding must only ever touch rows with a NULL external_id. A row already
     * bound to a different subject is somebody else's account.
     */
    public function test_refuses_to_rebind_a_row_that_already_has_a_different_external_id(): void
    {
        config(['quadsso.sso.allow_legacy_email_binding' => true]);

        $victim = User::create([
            'name' => 'Victim', 'email' => 'shared@example.test',
            'scim_external_id' => 'sub-victim', 'status' => 'active',
        ]);

        $this->fakeIdpUser(sub: 'sub-intruder', email: 'shared@example.test', emailVerified: true);

        $this->hitCallback()->assertRedirect('/login');

        $this->assertSame('sub-victim', $victim->fresh()->scim_external_id);
        $this->assertGuest();
    }

    public function test_does_not_bind_when_legacy_binding_is_disabled(): void
    {
        config(['quadsso.sso.allow_legacy_email_binding' => false]);

        $user = User::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'scim_external_id' => null, 'status' => 'active',
        ]);

        $this->fakeIdpUser(sub: 'sub-ada', email: 'ada@example.test');

        $this->hitCallback()->assertRedirect('/login');

        $this->assertNull($user->fresh()->scim_external_id);
        $this->assertGuest();
    }

    // ---------------------------------------------------------------------
    // JIT provisioning
    // ---------------------------------------------------------------------

    public function test_jit_disabled_creates_nobody(): void
    {
        config([
            'quadsso.sso.enable_jit_provisioning' => false,
            'quadsso.sso.allow_legacy_email_binding' => false,
        ]);

        $this->fakeIdpUser(sub: 'sub-new', email: 'new@example.test');

        $this->hitCallback()->assertRedirect('/login');

        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    public function test_jit_creates_user_with_defaults_applied(): void
    {
        config(['quadsso.sso.enable_jit_provisioning' => true]);

        $this->fakeIdpUser(sub: 'sub-new', email: 'new@example.test', name: 'Grace Hopper');

        $this->hitCallback()->assertRedirect('/home');

        $user = User::firstWhere('scim_external_id', 'sub-new');

        $this->assertNotNull($user, 'JIT should have created the user');
        $this->assertSame('new@example.test', $user->email);
        $this->assertSame('Grace Hopper', $user->name);
        $this->assertSame('active', $user->status);
        $this->assertSame('user', $user->level, 'observer default level should be applied');
        $this->assertNotEmpty($user->password, 'a random password hash must be set');
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_jit_refuses_when_idp_says_email_is_unverified(): void
    {
        config(['quadsso.sso.enable_jit_provisioning' => true]);

        $this->fakeIdpUser(sub: 'sub-new', email: 'new@example.test', emailVerified: false);

        $this->hitCallback()->assertRedirect('/login');

        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    /**
     * JIT must not quietly attach a new subject to an address that already
     * belongs to an established identity.
     */
    public function test_jit_refuses_on_email_collision_with_a_bound_user(): void
    {
        config(['quadsso.sso.enable_jit_provisioning' => true]);

        $existing = User::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'scim_external_id' => 'sub-ada', 'status' => 'active',
        ]);

        $this->fakeIdpUser(sub: 'sub-impostor', email: 'ada@example.test');

        $this->hitCallback()->assertRedirect('/login');

        $this->assertSame(1, User::count());
        $this->assertSame('sub-ada', $existing->fresh()->scim_external_id);
        $this->assertGuest();
    }

    public function test_jit_refuses_when_idp_returns_no_email(): void
    {
        config(['quadsso.sso.enable_jit_provisioning' => true]);

        $this->fakeIdpUser(sub: 'sub-new', email: null);

        $this->hitCallback()->assertRedirect('/login');
        $this->assertSame(0, User::count());
    }

    // ---------------------------------------------------------------------
    // Refusals that apply regardless of provisioning mode
    // ---------------------------------------------------------------------

    public function test_refuses_login_when_idp_returns_no_subject(): void
    {
        config(['quadsso.sso.enable_jit_provisioning' => true]);

        $this->fakeIdpUser(sub: null, email: 'ada@example.test');

        $this->hitCallback()->assertRedirect('/login');

        $this->assertSame(0, User::count(), 'a missing sub must never provision anyone');
        $this->assertGuest();
    }

    public function test_blocked_user_cannot_log_in(): void
    {
        User::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'scim_external_id' => 'sub-ada', 'status' => 'blocked',
        ]);

        $this->fakeIdpUser(sub: 'sub-ada');

        $this->hitCallback()->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_blocked_status_value_is_configurable(): void
    {
        config(['quadsso.provisioning.blocked_status_value' => 'suspended']);

        User::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'scim_external_id' => 'sub-ada', 'status' => 'suspended',
        ]);

        $this->fakeIdpUser(sub: 'sub-ada');

        $this->hitCallback()->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_is_blocked_method_on_the_model_is_honoured(): void
    {
        config([
            'quadsso.user_model' => BlockableUser::class,
            'auth.providers.users.model' => BlockableUser::class,
        ]);

        // The password default is applied by an observer bound to the model
        // configured at boot time, so a model swapped in mid-test doesn't get
        // it. Supply one explicitly rather than relying on that.
        BlockableUser::create([
            'name' => 'Blocked', 'email' => 'blocked-by-method@example.test',
            'scim_external_id' => 'sub-blocked', 'status' => 'active',
            'password' => bcrypt('irrelevant'),
        ]);

        $this->fakeIdpUser(sub: 'sub-blocked', email: 'blocked-by-method@example.test');

        $this->hitCallback()->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_failed_idp_handshake_does_not_authenticate_anyone(): void
    {
        User::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'scim_external_id' => 'sub-ada', 'status' => 'active',
        ]);

        $this->fakeIdpFailure();

        $this->hitCallback()->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_unverified_email_is_not_stamped_as_verified(): void
    {
        $user = User::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'scim_external_id' => 'sub-ada', 'status' => 'active',
            'email_verified_at' => null,
        ]);

        $this->fakeIdpUser(sub: 'sub-ada', emailVerified: false);

        $this->hitCallback();

        $this->assertNull(
            $user->fresh()->email_verified_at,
            'an unverified address must not be laundered into a verified one'
        );
    }
}
