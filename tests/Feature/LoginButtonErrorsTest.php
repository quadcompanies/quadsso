<?php

namespace QuadCompanies\QuadSSO\Tests\Feature;

use Illuminate\Routing\Router;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use QuadCompanies\QuadSSO\Tests\Concerns\MocksSocialite;
use QuadCompanies\QuadSSO\Tests\TestCase;

/**
 * A failed SSO login redirects back to the login page with a flashed message.
 * If nothing renders it the redirect is indistinguishable from a page refresh,
 * which is the whole reason the button carries its own error region.
 */
class LoginButtonErrorsTest extends TestCase
{
    use MocksSocialite;

    /**
     * Share the bag the way ShareErrorsFromSession does in a real request.
     * Passing it as view data would not reach the component, which sees only
     * its props plus shared data.
     */
    private function shareErrors(array $messages, ?string $bag = null): void
    {
        $bag ??= config('quadsso.ui.error_bag', 'quadsso');

        \Illuminate\Support\Facades\View::share(
            'errors',
            (new ViewErrorBag())->put($bag, new MessageBag(['sso' => $messages]))
        );
    }

    /** Share the errors, then render the component. */
    private function shareAnd(string $template, array $messages, ?string $bag = null)
    {
        $this->shareErrors($messages, $bag);

        return $this->blade($template);
    }

    // ---------------------------------------------------------------------
    // Rendering
    // ---------------------------------------------------------------------

    public function test_the_error_region_is_always_present(): void
    {
        $this->blade('<x-quadsso::login-button />')
            ->assertSee('data-quadsso-login-error', false);
    }

    public function test_the_region_is_empty_and_unstyled_with_no_error(): void
    {
        $rendered = (string) $this->blade('<x-quadsso::login-button />');

        $this->assertStringContainsString('data-quadsso-login-error', $rendered);
        $this->assertStringNotContainsString('bg-red-50', $rendered, 'an empty region must stay invisible');
    }

    public function test_a_flashed_message_is_displayed(): void
    {
        $this->shareAnd('<x-quadsso::login-button />', ['Your account has been suspended.'], null)
            ->assertSee('Your account has been suspended.');
    }

    public function test_a_displayed_message_gets_alert_styling(): void
    {
        $rendered = (string) $this->shareAnd('<x-quadsso::login-button />', ['Boom'], null);

        $this->assertStringContainsString('bg-red-50', $rendered);
    }

    public function test_the_region_appears_above_the_button(): void
    {
        $rendered = (string) $this->shareAnd('<x-quadsso::login-button />', ['Boom'], null);

        $this->assertLessThan(
            strpos($rendered, '<a'),
            strpos($rendered, 'data-quadsso-login-error'),
            'the error region has to come first or it reads as unrelated to the button'
        );
    }

    public function test_multiple_messages_are_all_shown(): void
    {
        $rendered = (string) $this->shareAnd('<x-quadsso::login-button />', ['First problem', 'Second problem'], null);

        $this->assertStringContainsString('First problem', $rendered);
        $this->assertStringContainsString('Second problem', $rendered);
    }

    public function test_messages_are_escaped(): void
    {
        $rendered = (string) $this->shareAnd('<x-quadsso::login-button />', ['<script>x</script>'], null);

        $this->assertStringNotContainsString('<script>x</script>', $rendered);
        $this->assertStringContainsString('&lt;script&gt;', $rendered);
    }

    public function test_the_region_carries_alert_semantics(): void
    {
        $rendered = (string) $this->blade('<x-quadsso::login-button />');

        $this->assertStringContainsString('role="alert"', $rendered);
        $this->assertStringContainsString('aria-live="polite"', $rendered);
    }

    // ---------------------------------------------------------------------
    // Opting out and overriding
    // ---------------------------------------------------------------------

    public function test_the_region_can_be_switched_off(): void
    {
        $rendered = (string) $this->shareAnd('<x-quadsso::login-button :show-errors="false" />', ['Boom'], null);

        $this->assertStringNotContainsString('data-quadsso-login-error', $rendered);
        $this->assertStringNotContainsString('Boom', $rendered);
    }

    public function test_error_styling_can_be_replaced(): void
    {
        $rendered = (string) $this->shareAnd('<x-quadsso::login-button error-class="alert alert-danger" />', ['Boom'], null);

        $this->assertStringContainsString('alert alert-danger', $rendered);
        $this->assertStringNotContainsString('bg-red-50', $rendered);
    }

    public function test_unstyled_drops_the_error_styling_too(): void
    {
        $rendered = (string) $this->shareAnd('<x-quadsso::login-button :unstyled="true" />', ['Boom'], null);

        $this->assertStringContainsString('Boom', $rendered, 'the message still shows');
        $this->assertStringNotContainsString('bg-red-50', $rendered);
    }

    public function test_messages_in_another_bag_are_ignored(): void
    {
        $rendered = (string) $this->shareAnd('<x-quadsso::login-button />', ['Unrelated form error'], 'default');

        $this->assertStringNotContainsString('Unrelated form error', $rendered);
    }

    public function test_the_bag_is_configurable(): void
    {
        config(['quadsso.ui.error_bag' => 'default']);

        $this->shareAnd('<x-quadsso::login-button />', ['Boom'], 'default')
            ->assertSee('Boom');
    }

    // ---------------------------------------------------------------------
    // End to end
    // ---------------------------------------------------------------------

    /**
     * The actual reported behaviour: a failed login used to look like a page
     * refresh. Drives the real callback, follows the redirect, and checks the
     * message reaches the rendered page.
     */
    public function test_a_failed_login_surfaces_its_reason_on_the_login_page(): void
    {
        config([
            'quadsso.sso.enable_jit_provisioning' => false,
            'quadsso.sso.allow_legacy_email_binding' => false,
        ]);

        $this->fakeIdpUser(sub: 'sub-nobody', email: 'nobody@example.test');

        $this->get('/auth/sso/callback')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['sso'], null, config('quadsso.ui.error_bag'));

        $this->followingRedirects()
            ->get('/auth/sso/callback')
            ->assertOk()
            ->assertSee('No account found for this identity. Please contact an administrator.');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->group(function (Router $r) {
            $r->get('login', fn() => view('quadsso-test::login'))->name('login');
        });
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // A stand-in for the host application's login page.
        $app['view']->addNamespace('quadsso-test', __DIR__ . '/../Fixtures/views');
    }
}
