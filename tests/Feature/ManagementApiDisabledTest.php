<?php

namespace QuadCompanies\QuadSSO\Tests\Feature;

use Illuminate\Support\Facades\Route;
use QuadCompanies\QuadSSO\Tests\Fixtures\User;
use QuadCompanies\QuadSSO\Tests\TestCase;

/**
 * The endpoint is opt-in. While disabled the route is never registered at all,
 * so the path 404s instead of advertising itself with a 401 — an unconfigured
 * install should not disclose that an account-deletion endpoint exists.
 */
class ManagementApiDisabledTest extends TestCase
{
    public function test_management_api_is_off_by_default(): void
    {
        $this->assertFalse(config('quadsso.management.enabled'));
    }

    public function test_route_is_not_registered(): void
    {
        $this->assertNull(Route::getRoutes()->getByName('quadsso.management'));
    }

    public function test_endpoint_404s_rather_than_401ing(): void
    {
        $this->postJson('/api/quadsso-mgr', ['action' => 'DELETE', 'email' => 'ada@example.test'])
            ->assertNotFound();
    }

    public function test_no_user_can_be_touched_while_disabled(): void
    {
        $user = User::create([
            'name' => 'Ada', 'email' => 'ada@example.test',
            'scim_external_id' => 'sub-ada', 'status' => 'active',
        ]);

        $this->postJson(
            '/api/quadsso-mgr',
            ['action' => 'DELETE', 'email' => $user->email],
            ['X-QuadSSO-Key' => 'anything']
        )->assertNotFound();

        $this->assertNotNull(User::find($user->id));
        $this->assertSame('active', $user->fresh()->status);
    }
}
