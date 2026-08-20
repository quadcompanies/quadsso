<?php

namespace QuadCompanies\QuadSSO;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use QuadCompanies\QuadSSO\Support\QuadSsoLog;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use SocialiteProviders\Manager\SocialiteWasCalled;

class QuadSSOServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/quadsso.php', 'quadsso');
    }

    public function boot(): void
    {
        // Route file applies its own middleware per route — see routes/quadsso.php
        // for why SLO must stay outside the 'web' group (CSRF would reject every
        // back-channel logout from Authentik with HTTP 419).
        $this->loadRoutesFrom(__DIR__ . '/../routes/quadsso.php');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Anonymous components under resources/views/components resolve as
        // <x-quadsso::login-button /> once the namespace is registered.
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'quadsso');

        $this->publishes([
            __DIR__ . '/../config/quadsso.php' => config_path('quadsso.php'),
        ], 'quadsso-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'quadsso-migrations');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/quadsso'),
        ], 'quadsso-views');

        $this->registerBladeDirectives();

        // Register the authentik Socialite provider
        Event::listen(SocialiteWasCalled::class, function (SocialiteWasCalled $event) {
            $event->extendSocialite('authentik', \SocialiteProviders\Authentik\Provider::class);
        });

        $this->blockLocalAuthRoutes();

        $this->registerUserObserver();

        $this->validateSchemaConfiguration();
    }

    /**
     * Convenience alias for <x-quadsso::login-button />.
     *
     *   @ssoLoginButton
     *   @ssoLoginButton('Sign in with Acme ID')
     *
     * The directive takes a label only. Anything that needs classes, a slot or
     * other attributes should use the component, which is what this renders.
     */
    protected function registerBladeDirectives(): void
    {
        Blade::directive('ssoLoginButton', function ($expression) {
            $text = trim($expression) === '' ? 'null' : trim($expression);

            return "<?php echo view('quadsso::components.login-button', ["
                . "'text' => {$text},"
                . "'attributes' => new \\Illuminate\\View\\ComponentAttributeBag(),"
                . "'slot' => new \\Illuminate\\View\\ComponentSlot(),"
                . "])->render(); ?>";
        });
    }

    /**
     * Optionally block the host application's registration and password-reset
     * routes, so the identity provider stays the only way in.
     *
     * Added to the 'web' group rather than registered globally: group middleware
     * runs inside the route pipeline, so the route is already resolved and its
     * name is available to match against.
     *
     * Registered through the HTTP kernel, not Router::pushMiddlewareToGroup().
     * The kernel owns the canonical group definitions and syncs them onto the
     * router when it is constructed — which can happen after this provider
     * boots, silently discarding a router-level push. Appending via the kernel
     * updates the definition itself, so it survives that sync. The router push
     * remains as a fallback for containers with no HTTP kernel bound.
     */
    protected function blockLocalAuthRoutes(): void
    {
        if (!config('quadsso.disable_local_auth.enabled', false)) {
            return;
        }

        $middleware = \QuadCompanies\QuadSSO\Middleware\BlockLocalAuthRoutes::class;

        $this->app->booted(function () use ($middleware) {
            if ($this->app->bound(\Illuminate\Contracts\Http\Kernel::class)) {
                $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);

                if (method_exists($kernel, 'appendMiddlewareToGroup')) {
                    $kernel->appendMiddlewareToGroup('web', $middleware);

                    return;
                }
            }

            $this->app['router']->pushMiddlewareToGroup('web', $middleware);
        });
    }

    /**
     * Fill in the columns a provisioned user needs but the IdP never supplies.
     *
     * Note this is a global `creating` model event: it applies to every user the
     * host application creates, not only those provisioned through SSO.
     */
    protected function registerUserObserver(): void
    {
        if (!config('quadsso.provisioning.apply_defaults', true)) {
            return;
        }

        $userModel = config('quadsso.user_model', \App\Models\User::class);

        if (!class_exists($userModel)) {
            return;
        }

        $userModel::creating(function ($user) {
            // Provisioned users arrive without a password; give them an unusable one.
            if (empty($user->password)) {
                $user->password = Hash::make(Str::random(32));
            }

            if ($defaultLevel = config('quadsso.provisioning.default_user_level')) {
                $levelField = config('quadsso.provisioning.user_level_field', 'level');

                if (empty($user->{$levelField})) {
                    $user->{$levelField} = $defaultLevel;
                }
            }

            if ($defaultStatus = config('quadsso.provisioning.default_user_status')) {
                $statusField = config('quadsso.provisioning.user_status_field', 'status');

                if (empty($user->{$statusField})) {
                    $user->{$statusField} = $defaultStatus;
                }
            }
        });
    }

    /**
     * Validate that configured field mappings match the database schema.
     * Logs warnings for missing columns instead of throwing exceptions to allow
     * developers to publish config first before running migrations.
     */
    protected function validateSchemaConfiguration(): void
    {
        // Skip validation in console commands (migrations, etc.) to avoid chicken-egg issues
        if ($this->app->runningInConsole() && !$this->app->runningUnitTests()) {
            return;
        }

        try {
            if (!Schema::hasTable('users')) {
                return;
            }
        } catch (\Exception $e) {
            return;
        }

        // Every config key that names a column, not just field_mappings. The
        // provisioning columns were previously unchecked, which let a typo in
        // user_status_field disable the login block check silently.
        $configuredColumns = [];

        foreach (config('quadsso.field_mappings', []) as $ssoField => $dbColumn) {
            $configuredColumns["field_mappings.$ssoField"] = $dbColumn;
        }

        $configuredColumns['provisioning.user_status_field'] = config('quadsso.provisioning.user_status_field');
        $configuredColumns['provisioning.user_level_field'] = config('quadsso.provisioning.user_level_field');

        $missingColumns = [];

        foreach ($configuredColumns as $configKey => $dbColumn) {
            if ($dbColumn !== null && $dbColumn !== '' && !Schema::hasColumn('users', $dbColumn)) {
                $missingColumns[] = [
                    'sso_field' => $configKey,
                    'column' => $dbColumn,
                ];
            }
        }

        if (!empty($missingColumns)) {
            $columnList = collect($missingColumns)
                ->map(fn($item) => "'{$item['column']}' (mapped from '{$item['sso_field']}')")
                ->join(', ');

            QuadSsoLog::warning(
                "Missing database columns in 'users' table: $columnList. " .
                "User provisioning may fail, and a missing status column means blocked " .
                "accounts cannot be detected — logins are refused rather than admitted. " .
                "Either add these columns via migration, or point the config at columns " .
                "that exist in config/quadsso.php."
            );
        }
    }
}
