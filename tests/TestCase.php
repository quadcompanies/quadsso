<?php

namespace QuadCompanies\QuadSSO\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use QuadCompanies\QuadSSO\QuadSSOServiceProvider;
use QuadCompanies\QuadSSO\Tests\Fixtures\User;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            \Laravel\Socialite\SocialiteServiceProvider::class,
            \SocialiteProviders\Manager\ServiceProvider::class,
            QuadSSOServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // These tests create a sessions table and seed rows into it, so the
        // driver has to match or the revoker correctly declines to touch it.
        $app['config']->set('session.driver', 'database');

        $app['config']->set('quadsso.user_model', User::class);
        $app['config']->set('auth.providers.users.model', User::class);

        $app['config']->set('quadsso.authentik.client_id', 'test-client-id');
        $app['config']->set('quadsso.authentik.base_url', 'https://idp.example.test');
        $app['config']->set('quadsso.authentik.jwks_uri', 'https://idp.example.test/jwks');

        $app['config']->set('services.authentik', [
            'client_id'     => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'redirect'      => 'https://app.example.test/auth/sso/callback',
            'base_url'      => 'https://idp.example.test',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();

        // The package migrations are registered by the service provider; run
        // them on top of the base users table.
        $this->artisan('migrate', ['--database' => 'testing'])->run();

        // Laravel only ships a sessions table by default from 11.x onward, and
        // SLO deletes from it directly. Create it explicitly so these tests
        // behave the same on every supported Laravel version.
        if (!Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }
    }

    /**
     * Insert a session row the way the database session driver would.
     */
    protected function seedSession(int $userId, string $id = 'sess-1'): void
    {
        \Illuminate\Support\Facades\DB::table('sessions')->insert([
            'id'            => $id,
            'user_id'       => $userId,
            'ip_address'    => '127.0.0.1',
            'user_agent'    => 'phpunit',
            'payload'       => '',
            'last_activity' => time(),
        ]);
    }

    protected function sessionCountFor(int $userId): int
    {
        return \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $userId)->count();
    }
}
