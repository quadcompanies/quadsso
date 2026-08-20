<?php

namespace QuadCompanies\QuadSSO\Tests\Feature;

use Illuminate\Support\Facades\Log;
use QuadCompanies\QuadSSO\QuadSSOServiceProvider;
use QuadCompanies\QuadSSO\Tests\TestCase;

/**
 * The boot-time schema check warns about configured columns the users table
 * doesn't have. It used to cover only field_mappings, which let a typo in
 * user_status_field pass unremarked while silently disabling the login block
 * check — so the provisioning columns are checked too.
 *
 * Driven through reflection because the real call happens during boot, before a
 * test can install a log spy.
 */
class SchemaValidationTest extends TestCase
{
    private function runValidator(): void
    {
        $provider = new QuadSSOServiceProvider($this->app);

        $method = new \ReflectionMethod($provider, 'validateSchemaConfiguration');
        $method->setAccessible(true);
        $method->invoke($provider);
    }

    public function test_says_nothing_when_every_configured_column_exists(): void
    {
        Log::spy();

        $this->runValidator();

        Log::shouldNotHaveReceived('warning');
    }

    public function test_warns_about_a_missing_status_column(): void
    {
        config(['quadsso.provisioning.user_status_field' => 'no_such_status']);

        Log::spy();

        $this->runValidator();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn(string $message) => str_contains($message, 'no_such_status')
                && str_contains($message, 'provisioning.user_status_field'))
            ->once();
    }

    public function test_warns_about_a_missing_level_column(): void
    {
        config(['quadsso.provisioning.user_level_field' => 'no_such_level']);

        Log::spy();

        $this->runValidator();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn(string $message) => str_contains($message, 'no_such_level'))
            ->once();
    }

    public function test_still_warns_about_missing_field_mappings(): void
    {
        config(['quadsso.field_mappings.email' => 'no_such_email']);

        Log::spy();

        $this->runValidator();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn(string $message) => str_contains($message, 'no_such_email'))
            ->once();
    }

    public function test_null_and_empty_mappings_are_not_reported(): void
    {
        config([
            'quadsso.field_mappings.phone_cell' => null,
            'quadsso.provisioning.user_level_field' => '',
        ]);

        Log::spy();

        $this->runValidator();

        Log::shouldNotHaveReceived('warning');
    }
}
