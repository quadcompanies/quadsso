<?php

namespace QuadCompanies\QuadSSO\Tests\Feature;

use QuadCompanies\QuadSSO\Tests\Concerns\MocksSocialite;
use QuadCompanies\QuadSSO\Tests\Fixtures\AccessorStatusUser;
use QuadCompanies\QuadSSO\Tests\Fixtures\BlockableUser;
use QuadCompanies\QuadSSO\Tests\Fixtures\User;
use QuadCompanies\QuadSSO\Tests\TestCase;

/**
 * Two separate injection surfaces:
 *
 *  - Values the IdP controls (sub, email, display name) reach the query builder
 *    as bindings. They must be treated as data, never as SQL.
 *  - Column names come from configuration and are interpolated into the
 *    *identifier* position, which bindings do not protect. A hostile or
 *    fat-fingered value there must fail closed — erroring or matching nothing —
 *    rather than widening a lookup into "match any row".
 */
class ConfigInjectionTest extends TestCase
{
    use MocksSocialite;

    private function hitCallback()
    {
        return $this->get('/auth/sso/callback');
    }

    // ---------------------------------------------------------------------
    // IdP-controlled values
    // ---------------------------------------------------------------------

    public function test_sql_metacharacters_in_the_subject_match_nobody(): void
    {
        config([
            'quadsso.sso.enable_jit_provisioning' => false,
            'quadsso.sso.allow_legacy_email_binding' => false,
        ]);

        User::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'scim_external_id' => 'sub-ada', 'status' => 'active',
        ]);

        $this->fakeIdpUser(sub: "' OR '1'='1", email: 'ada@example.test');

        $this->hitCallback();

        $this->assertFalse(auth()->check(), 'a tautology in the sub must not match an existing row');
    }

    public function test_sql_metacharacters_in_the_subject_are_stored_as_literal_data(): void
    {
        config(['quadsso.sso.enable_jit_provisioning' => true]);

        $payload = "' OR '1'='1";

        $this->fakeIdpUser(sub: $payload, email: 'new@example.test');

        $this->hitCallback();

        $user = User::firstWhere('email', 'new@example.test');

        $this->assertNotNull($user);
        $this->assertSame($payload, $user->scim_external_id, 'the sub must round-trip as a literal string');
    }

    public function test_sql_metacharacters_in_the_email_do_not_widen_legacy_binding(): void
    {
        config([
            'quadsso.sso.allow_legacy_email_binding' => true,
            'quadsso.sso.enable_jit_provisioning' => false,
        ]);

        $user = User::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'scim_external_id' => null, 'status' => 'active',
        ]);

        $this->fakeIdpUser(sub: 'sub-intruder', email: "ada@example.test' OR '1'='1");

        $this->hitCallback();

        $this->assertNull($user->fresh()->scim_external_id, 'binding must not match on an injected email');
        $this->assertGuest();
    }

    public function test_sql_metacharacters_in_the_display_name_are_stored_literally(): void
    {
        config(['quadsso.sso.enable_jit_provisioning' => true]);

        $payload = "Robert'); DROP TABLE users;--";

        $this->fakeIdpUser(sub: 'sub-bobby', email: 'bobby@example.test', name: $payload);

        $this->hitCallback();

        $this->assertSame(1, User::count(), 'the users table should still be there');
        $this->assertSame($payload, User::firstWhere('email', 'bobby@example.test')->name);
    }

    // ---------------------------------------------------------------------
    // Config-controlled identifiers
    // ---------------------------------------------------------------------

    public function test_hostile_external_id_column_fails_closed(): void
    {
        User::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'scim_external_id' => 'sub-ada', 'status' => 'active',
        ]);

        config(['quadsso.field_mappings.external_id' => 'scim_external_id" OR "1"="1']);

        $this->fakeIdpUser(sub: 'whatever', email: 'ada@example.test');

        $this->hitCallback();

        $this->assertFalse(auth()->check(), 'a broken column mapping must never authenticate anyone');
    }

    /**
     * The status column name is only ever used for attribute access, never
     * interpolated into SQL, so there is no injection here. The risk is
     * different: a name that doesn't exist reads back as null, null never
     * equals the blocked value, and the block check would pass — admitting
     * suspended accounts silently.
     *
     * So the callback refuses the login instead. Locking everyone out on a typo
     * is loud and fixed in minutes; admitting terminated staff is neither.
     */
    public function test_block_check_fails_closed_on_a_misconfigured_status_column(): void
    {
        User::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'scim_external_id' => 'sub-ada', 'status' => 'blocked',
        ]);

        config(['quadsso.provisioning.user_status_field' => 'no_such_column']);

        $this->fakeIdpUser(sub: 'sub-ada', email: 'ada@example.test');

        $this->hitCallback()->assertRedirect('/login');

        $this->assertFalse(
            auth()->check(),
            'a blocked user must not be admitted because the column name is wrong'
        );
    }

    /**
     * The same refusal applies to an account that is not blocked: once the
     * status column cannot be read, the check cannot distinguish the two, so it
     * refuses rather than guessing.
     */
    public function test_misconfigured_status_column_refuses_active_users_too(): void
    {
        User::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'scim_external_id' => 'sub-ada', 'status' => 'active',
        ]);

        config(['quadsso.provisioning.user_status_field' => 'no_such_column']);

        $this->fakeIdpUser(sub: 'sub-ada', email: 'ada@example.test');

        $this->hitCallback()->assertRedirect('/login');
        $this->assertGuest();
    }

    /**
     * Opting out of status-based blocking is an explicit choice, not a typo, so
     * an empty blocked value skips the requirement entirely.
     */
    public function test_empty_blocked_value_opts_out_of_the_status_requirement(): void
    {
        User::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'scim_external_id' => 'sub-ada', 'status' => 'active',
        ]);

        config([
            'quadsso.provisioning.user_status_field' => 'no_such_column',
            'quadsso.provisioning.blocked_status_value' => '',
        ]);

        $this->fakeIdpUser(sub: 'sub-ada', email: 'ada@example.test');

        $this->hitCallback()->assertRedirect('/home');
        $this->assertTrue(auth()->check());
    }

    /**
     * A model with its own isBlocked() gate does not need the column either.
     */
    public function test_models_with_their_own_block_check_do_not_need_the_column(): void
    {
        config([
            'quadsso.user_model' => BlockableUser::class,
            'auth.providers.users.model' => BlockableUser::class,
            'quadsso.provisioning.user_status_field' => 'no_such_column',
        ]);

        BlockableUser::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'scim_external_id' => 'sub-ada', 'status' => 'active',
            'password' => bcrypt('irrelevant'),
        ]);

        $this->fakeIdpUser(sub: 'sub-ada', email: 'ada@example.test');

        $this->hitCallback()->assertRedirect('/home');
        $this->assertTrue(auth()->check());
    }

    /**
     * ...and that gate still blocks, with no status column in play at all.
     */
    public function test_own_block_check_still_denies_without_a_status_column(): void
    {
        config([
            'quadsso.user_model' => BlockableUser::class,
            'auth.providers.users.model' => BlockableUser::class,
            'quadsso.provisioning.user_status_field' => 'no_such_column',
        ]);

        BlockableUser::create([
            'name' => 'Blocked', 'email' => 'blocked-by-method@example.test',
            'scim_external_id' => 'sub-blocked', 'status' => 'active',
            'password' => bcrypt('irrelevant'),
        ]);

        $this->fakeIdpUser(sub: 'sub-blocked', email: 'blocked-by-method@example.test');

        $this->hitCallback()->assertRedirect('/login');
        $this->assertGuest();
    }

    /**
     * A status exposed through an accessor rather than stored in a column is a
     * legitimate design and must not be mistaken for a misconfiguration.
     */
    public function test_a_status_accessor_satisfies_the_check(): void
    {
        config([
            'quadsso.user_model' => AccessorStatusUser::class,
            'auth.providers.users.model' => AccessorStatusUser::class,
            'quadsso.provisioning.user_status_field' => 'derived_status',
        ]);

        AccessorStatusUser::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'scim_external_id' => 'sub-ada', 'status' => 'active',
            'password' => bcrypt('irrelevant'),
        ]);

        $this->fakeIdpUser(sub: 'sub-ada', email: 'ada@example.test');

        $this->hitCallback()->assertRedirect('/home');
        $this->assertTrue(auth()->check());
    }

    public function test_hostile_email_column_does_not_provision_anyone(): void
    {
        config(['quadsso.sso.enable_jit_provisioning' => true]);
        config(['quadsso.field_mappings.email' => 'email" OR "1"="1']);

        $this->fakeIdpUser(sub: 'sub-new', email: 'new@example.test');

        $this->hitCallback();

        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    /**
     * A mapping pointing at a column the model doesn't expose is discarded by
     * mass assignment — it never reaches the INSERT. Where that leaves a NOT
     * NULL column unwritten, provisioning fails and the login is refused rather
     * than producing a half-populated user.
     */
    public function test_misdirected_mapping_for_a_required_column_fails_closed(): void
    {
        config(['quadsso.sso.enable_jit_provisioning' => true]);
        config(['quadsso.field_mappings.name' => 'not_a_real_column']);

        $this->fakeIdpUser(sub: 'sub-new', email: 'new@example.test', name: 'Grace Hopper');

        $this->hitCallback()->assertRedirect('/login');

        $this->assertSame(0, User::count(), 'no half-populated row should survive');
        $this->assertGuest();
    }
}
