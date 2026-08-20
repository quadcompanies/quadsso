<?php

namespace QuadCompanies\QuadSSO\Tests\Feature;

use QuadCompanies\QuadSSO\Tests\TestCase;

/**
 * The button is the one part of this package an integrator drops into their own
 * markup, so the contract worth pinning down is: it always points at the real
 * SSO route, and it gets out of the way of the host application's styling.
 */
class LoginButtonTest extends TestCase
{
    // ---------------------------------------------------------------------
    // Destination
    // ---------------------------------------------------------------------

    public function test_links_to_the_sso_redirect_route(): void
    {
        $this->blade('<x-quadsso::login-button />')
            ->assertSee('href="' . route('sso.redirect') . '"', false);
    }

    public function test_renders_an_anchor_not_a_form(): void
    {
        $rendered = (string) $this->blade('<x-quadsso::login-button />');

        $this->assertStringContainsString('<a', $rendered);
        $this->assertStringNotContainsString('<form', $rendered);
    }

    // ---------------------------------------------------------------------
    // Label
    // ---------------------------------------------------------------------

    public function test_defaults_to_login_via_sso(): void
    {
        $this->blade('<x-quadsso::login-button />')->assertSee('Login via SSO');
    }

    public function test_label_can_be_passed_as_a_prop(): void
    {
        $this->blade('<x-quadsso::login-button text="Sign in with Acme ID" />')
            ->assertSee('Sign in with Acme ID')
            ->assertDontSee('Login via SSO');
    }

    public function test_label_default_is_configurable(): void
    {
        config(['quadsso.ui.button_label' => 'Continue with Acme']);

        $this->blade('<x-quadsso::login-button />')
            ->assertSee('Continue with Acme')
            ->assertDontSee('Login via SSO');
    }

    public function test_prop_wins_over_the_configured_default(): void
    {
        config(['quadsso.ui.button_label' => 'Continue with Acme']);

        $this->blade('<x-quadsso::login-button text="Explicit" />')
            ->assertSee('Explicit')
            ->assertDontSee('Continue with Acme');
    }

    public function test_slot_content_overrides_the_label_entirely(): void
    {
        $rendered = (string) $this->blade(
            '<x-quadsso::login-button><svg id="icon"></svg>Custom</x-quadsso::login-button>'
        );

        $this->assertStringContainsString('<svg id="icon">', $rendered);
        $this->assertStringContainsString('Custom', $rendered);
        $this->assertStringNotContainsString('Login via SSO', $rendered);
    }

    public function test_label_is_escaped(): void
    {
        $rendered = (string) $this->blade('<x-quadsso::login-button text="<script>x</script>" />');

        $this->assertStringNotContainsString('<script>x</script>', $rendered);
        $this->assertStringContainsString('&lt;script&gt;', $rendered);
    }

    // ---------------------------------------------------------------------
    // Styling
    // ---------------------------------------------------------------------

    public function test_ships_with_structural_tailwind_classes(): void
    {
        $rendered = (string) $this->blade('<x-quadsso::login-button />');

        foreach (['inline-flex', 'rounded-md', 'px-4', 'py-2.5', 'font-semibold'] as $class) {
            $this->assertStringContainsString($class, $rendered);
        }
    }

    public function test_applies_a_default_palette_when_none_is_given(): void
    {
        $this->blade('<x-quadsso::login-button />')->assertSee('bg-indigo-600', false);
    }

    public function test_extra_classes_are_merged_in(): void
    {
        $rendered = (string) $this->blade('<x-quadsso::login-button class="w-full mt-4" />');

        $this->assertStringContainsString('w-full', $rendered);
        $this->assertStringContainsString('mt-4', $rendered);
        $this->assertStringContainsString('inline-flex', $rendered, 'structural defaults survive');
    }

    /**
     * Tailwind picks the winner between two background utilities by stylesheet
     * order, so emitting both would make the result depend on palette ordering.
     * The default palette has to step aside instead.
     */
    public function test_supplying_a_background_drops_the_default_palette(): void
    {
        $rendered = (string) $this->blade('<x-quadsso::login-button class="bg-emerald-600 text-black" />');

        $this->assertStringContainsString('bg-emerald-600', $rendered);
        $this->assertStringNotContainsString('bg-indigo-600', $rendered);
        $this->assertStringNotContainsString('hover:bg-indigo-500', $rendered);
        $this->assertStringContainsString('inline-flex', $rendered, 'structure is still applied');
    }

    public function test_dark_mode_background_also_drops_the_default_palette(): void
    {
        $rendered = (string) $this->blade('<x-quadsso::login-button class="dark:bg-slate-800" />');

        $this->assertStringNotContainsString('bg-indigo-600', $rendered);
    }

    public function test_unstyled_emits_no_classes_of_its_own(): void
    {
        $rendered = (string) $this->blade('<x-quadsso::login-button :unstyled="true" />');

        $this->assertStringNotContainsString('inline-flex', $rendered);
        $this->assertStringNotContainsString('bg-indigo-600', $rendered);
        $this->assertStringContainsString('href=', $rendered);
    }

    public function test_unstyled_still_accepts_caller_classes(): void
    {
        $rendered = (string) $this->blade('<x-quadsso::login-button :unstyled="true" class="btn btn-primary" />');

        $this->assertStringContainsString('btn btn-primary', $rendered);
        $this->assertStringNotContainsString('inline-flex', $rendered);
    }

    public function test_arbitrary_attributes_pass_through(): void
    {
        $rendered = (string) $this->blade(
            '<x-quadsso::login-button id="sso" data-testid="sso-button" aria-label="SSO" />'
        );

        $this->assertStringContainsString('id="sso"', $rendered);
        $this->assertStringContainsString('data-testid="sso-button"', $rendered);
        $this->assertStringContainsString('aria-label="SSO"', $rendered);
    }

    // ---------------------------------------------------------------------
    // Directive alias
    // ---------------------------------------------------------------------

    public function test_directive_renders_the_button(): void
    {
        $this->blade('@ssoLoginButton')
            ->assertSee('Login via SSO')
            ->assertSee('href="' . route('sso.redirect') . '"', false);
    }

    public function test_directive_accepts_a_label(): void
    {
        $this->blade("@ssoLoginButton('Sign in with Acme ID')")
            ->assertSee('Sign in with Acme ID')
            ->assertDontSee('Login via SSO');
    }

    public function test_directive_output_matches_the_component(): void
    {
        $this->assertSame(
            trim((string) $this->blade('<x-quadsso::login-button />')),
            trim((string) $this->blade('@ssoLoginButton'))
        );
    }
}
