<?php

namespace QuadCompanies\QuadSSO\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use QuadCompanies\QuadSSO\Middleware\EnforceSessionRevocation;

/**
 * One short, quote-free command that reports whether this installation is
 * actually wired up — written because the alternative is asking an operator to
 * paste a 700-character tinker one-liner into a hosting console.
 *
 * It checks the things that fail silently: a session driver that keeps no
 * server-side record, a status column named in config that does not exist, a
 * revocation column that was never migrated.
 */
class DoctorCommand extends Command
{
    protected $signature = 'quadsso:doctor {--json : Emit machine-readable output}';

    protected $description = 'Check that QuadSSO is correctly configured for this application';

    /** @var array<int,array{area:string,check:string,status:string,detail:string}> */
    private array $results = [];

    public function handle(): int
    {
        $this->checkAuthentik();
        $this->checkSchema();
        $this->checkSessions();
        $this->checkLocalAuth();
        $this->checkManagementApi();
        $this->checkRoutes();

        $failed = collect($this->results)->where('status', 'FAIL')->count();
        $warned = collect($this->results)->where('status', 'WARN')->count();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ok'       => $failed === 0,
                'failures' => $failed,
                'warnings' => $warned,
                'checks'   => $this->results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Area', 'Check', 'Status', 'Detail'],
            array_map(fn(array $r) => [
                $r['area'],
                $r['check'],
                match ($r['status']) {
                    'PASS' => '<fg=green>PASS</>',
                    'WARN' => '<fg=yellow>WARN</>',
                    default => '<fg=red>FAIL</>',
                },
                $r['detail'],
            ], $this->results)
        );

        $this->newLine();

        if ($failed > 0) {
            $this->error("{$failed} check(s) failed, {$warned} warning(s).");

            return self::FAILURE;
        }

        $warned > 0
            ? $this->warn("All checks passed with {$warned} warning(s).")
            : $this->info('All checks passed.');

        return self::SUCCESS;
    }

    private function record(string $area, string $check, string $status, string $detail = ''): void
    {
        $this->results[] = compact('area', 'check', 'status', 'detail');
    }

    private function checkAuthentik(): void
    {
        foreach ([
            'client_id' => 'AUTHENTIK_CLIENT_ID',
            'base_url'  => 'AUTHENTIK_BASE_URL',
            'jwks_uri'  => 'AUTHENTIK_JWKS_URI',
        ] as $key => $env) {
            $value = config("quadsso.authentik.$key");

            $this->record(
                'authentik',
                $env,
                $value ? 'PASS' : ($key === 'jwks_uri' && !config('quadsso.sso.enable_slo', true) ? 'WARN' : 'FAIL'),
                $value ? 'set' : 'missing'
            );
        }

        $services = config('services.authentik.client_secret');
        $this->record('authentik', 'services.authentik.client_secret', $services ? 'PASS' : 'FAIL',
            $services ? 'set' : 'Socialite reads credentials from config/services.php');
    }

    private function checkSchema(): void
    {
        try {
            if (!Schema::hasTable('users')) {
                $this->record('schema', 'users table', 'FAIL', 'not found');

                return;
            }
        } catch (\Throwable $e) {
            $this->record('schema', 'database', 'FAIL', $e->getMessage());

            return;
        }

        $columns = [];

        foreach (config('quadsso.field_mappings', []) as $name => $column) {
            if ($column) {
                $columns["field_mappings.$name"] = $column;
            }
        }

        $columns['provisioning.user_status_field'] = config('quadsso.provisioning.user_status_field');
        $columns['provisioning.user_level_field'] = config('quadsso.provisioning.user_level_field');

        foreach ($columns as $key => $column) {
            if (!$column) {
                continue;
            }

            $exists = Schema::hasColumn('users', $column);

            // A missing status column is not cosmetic: the login block check
            // refuses every login rather than admitting blocked accounts.
            $isStatus = $key === 'provisioning.user_status_field';

            $this->record(
                'schema',
                $key,
                $exists ? 'PASS' : ($isStatus ? 'FAIL' : 'WARN'),
                $exists
                    ? "users.$column"
                    : "users.$column missing" . ($isStatus ? ' — all logins will be refused' : '')
            );
        }
    }

    private function checkSessions(): void
    {
        $driver = (string) config('session.driver');
        $serverSide = $driver === 'database';

        $this->record('sessions', 'session.driver', $serverSide ? 'PASS' : 'WARN',
            $serverSide ? $driver : "$driver keeps no server-side session record");

        if ($serverSide) {
            $connection = config('session.connection');
            $table = (string) (config('session.table') ?: 'sessions');

            try {
                $exists = Schema::connection($connection)->hasTable($table);
                $this->record('sessions', 'session table', $exists ? 'PASS' : 'FAIL',
                    ($connection ?: '(default)') . ".$table" . ($exists ? '' : ' not found'));

                if ($exists) {
                    $rows = DB::connection($connection)->table($table)->count();
                    $this->record('sessions', 'session rows', $rows > 0 ? 'PASS' : 'WARN',
                        $rows > 0 ? "$rows row(s)" : 'table is empty; sessions may not be landing here');
                }
            } catch (\Throwable $e) {
                $this->record('sessions', 'session table', 'FAIL', $e->getMessage());
            }
        }

        // The driver-agnostic half. On a stateless driver this is the only
        // thing that can end a session, so a missing column is fatal there.
        $enabled = (bool) config('quadsso.sessions.revocation', true);
        $field = (string) config('quadsso.sessions.revoked_at_field', 'quadsso_sessions_valid_after');

        if (!$enabled) {
            $this->record('sessions', 'revocation', $serverSide ? 'WARN' : 'FAIL',
                'disabled in config' . ($serverSide ? '' : ' — nothing can end a session on this driver'));

            return;
        }

        try {
            $hasColumn = Schema::hasColumn('users', $field);
        } catch (\Throwable $e) {
            $hasColumn = false;
        }

        $this->record('sessions', 'revocation column', $hasColumn ? 'PASS' : ($serverSide ? 'WARN' : 'FAIL'),
            $hasColumn ? "users.$field" : "users.$field missing — run php artisan migrate");

        $registered = $this->middlewareRegistered(EnforceSessionRevocation::class);
        $this->record('sessions', 'revocation middleware', $registered ? 'PASS' : 'FAIL',
            $registered ? 'active on the web group' : 'not registered on the web group');
    }

    /**
     * Whether the host application still exposes its own registration and
     * password-reset routes.
     *
     * Password reset is an SSO bypass: a provisioned user holds a random
     * password they never knew, but the reset flow will happily let them set
     * one at their IdP-verified address and sign in locally from then on —
     * never touching the IdP again, and surviving deactivation there.
     *
     * Reported against the real route table rather than the config alone, so
     * an application that simply has no such routes is not nagged about a
     * bypass it does not have.
     */
    private function checkLocalAuth(): void
    {
        $enabled = (bool) config('quadsso.disable_local_auth.enabled', true);
        $exposed = $this->localAuthRoutes();

        if (!$enabled) {
            $this->record(
                'local auth',
                'lockout',
                $exposed === [] ? 'PASS' : 'WARN',
                $exposed === []
                    ? 'disabled, but no local auth routes are registered'
                    : 'disabled while ' . count($exposed) . ' route(s) remain reachable, including '
                        . $exposed[0] . ' — password reset bypasses SSO'
            );

            return;
        }

        $this->record('local auth', 'lockout', 'PASS',
            $exposed === []
                ? 'enabled; no local auth routes registered'
                : 'enabled; ' . count($exposed) . ' route(s) blocked');

        $registered = $this->middlewareRegistered(
            \QuadCompanies\QuadSSO\Middleware\BlockLocalAuthRoutes::class
        );

        $this->record('local auth', 'lockout middleware', $registered ? 'PASS' : 'FAIL',
            $registered ? 'active on the web group' : 'not registered on the web group');

        // /login stays reachable on purpose: blocking it removes the only way
        // back in when the IdP itself is unavailable.
        $names = (array) config('quadsso.disable_local_auth.route_names', []);

        if (in_array('login', $names, true)) {
            $this->record('local auth', 'break-glass login', 'WARN',
                'login is blocked too; there is no way in if the IdP is unavailable');
        }
    }

    /**
     * Host routes the lockout would catch, matched the same way the middleware
     * matches them: by route name first, then URI pattern.
     */
    private function localAuthRoutes(): array
    {
        $names = (array) config('quadsso.disable_local_auth.route_names', []);
        $paths = (array) config('quadsso.disable_local_auth.paths', []);
        $found = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (str_starts_with($uri, 'auth/sso')) {
                continue;
            }

            $name = $route->getName();
            $matches = ($name !== null && in_array($name, $names, true))
                || ($paths !== [] && \Illuminate\Support\Str::is($paths, $uri));

            if ($matches) {
                $found[] = $route->methods()[0] . ' /' . $uri;
            }
        }

        return $found;
    }

    private function checkManagementApi(): void
    {
        if (!config('quadsso.management.enabled', false)) {
            $this->record('management', 'endpoint', 'PASS', 'disabled; route not registered');

            return;
        }

        $key = (string) config('quadsso.management.api_key', '');

        $this->record('management', 'api key', $key !== '' ? 'PASS' : 'FAIL',
            $key === '' ? 'not set — the endpoint will answer 503' : strlen($key) . ' characters');

        if ($key !== '' && strlen($key) < 32) {
            $this->record('management', 'api key strength', 'WARN', 'shorter than 32 characters');
        }

        $route = Route::getRoutes()->getByName('quadsso.management');
        $this->record('management', 'route', $route ? 'PASS' : 'FAIL',
            $route ? implode('|', $route->methods()) . ' /' . $route->uri() : 'not registered');
    }

    private function checkRoutes(): void
    {
        foreach (['sso.redirect', 'sso.callback', 'sso.logout'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->record('routes', $name, $route ? 'PASS' : 'FAIL',
                $route ? '/' . $route->uri() : 'not registered');
        }

        $slo = Route::getRoutes()->getByName('sso.logout');

        if ($slo) {
            $inWeb = in_array('web', $slo->gatherMiddleware(), true);
            $this->record('routes', 'sso.logout outside web', $inWeb ? 'FAIL' : 'PASS',
                $inWeb ? 'CSRF will reject every back-channel logout with HTTP 419' : 'no session or CSRF');
        }
    }

    private function middlewareRegistered(string $class): bool
    {
        $groups = app('router')->getMiddlewareGroups();

        if (in_array($class, $groups['web'] ?? [], true)) {
            return true;
        }

        if (!app()->bound(\Illuminate\Contracts\Http\Kernel::class)) {
            return false;
        }

        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);

        return method_exists($kernel, 'getMiddlewareGroups')
            && in_array($class, $kernel->getMiddlewareGroups()['web'] ?? [], true);
    }
}
