<?php

namespace QuadCompanies\QuadSSO\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Ends every existing session for a user, and reports honestly about what it
 * was actually able to do.
 *
 * Three mechanisms, applied together because no single one covers every setup:
 *
 *   1. Delete session rows — only meaningful on the `database` driver, and
 *      resolved through session.connection / session.table rather than
 *      assuming the default connection and a table literally named `sessions`.
 *
 *   2. Stamp a revocation timestamp on the user — driver-agnostic. Middleware
 *      rejects any session established before it, which is the only thing that
 *      works when the session lives in the client's cookie.
 *
 *   3. Cycle the remember token, and optionally the password hash, so
 *      remember-me cookies and Laravel's own AuthenticateSession both fail.
 *
 * The returned array says which of those actually happened. Callers surface it
 * rather than reporting a bare success: claiming to have terminated a session
 * that is still live is worse than admitting the limitation.
 */
class SessionRevoker
{
    /**
     * @return array{driver:string, rows_deleted:int|null, revocation_stamped:bool,
     *               remember_token_cycled:bool, password_cycled:bool,
     *               effective:bool, notes:array<int,string>}
     */
    public function revoke(Model $user): array
    {
        $notes = [];

        $rowsDeleted = $this->deleteSessionRows($user, $notes);
        $stamped = $this->stampRevocation($user, $notes);
        $rememberCycled = $this->cycleRememberToken($user);
        $passwordCycled = $this->cyclePassword($user, $notes);

        // Deleting rows only ends a session on a server-side driver; the stamp
        // works everywhere. If neither applied, nothing was actually revoked.
        $effective = $stamped || ($rowsDeleted !== null && $rowsDeleted > 0);

        if (!$effective) {
            $notes[] = 'no session was actually terminated: run the QuadSSO migrations to enable '
                . 'revocation, or check `php artisan quadsso:doctor`';
        }

        return [
            'driver'                => (string) config('session.driver'),
            'rows_deleted'          => $rowsDeleted,
            'revocation_stamped'    => $stamped,
            'remember_token_cycled' => $rememberCycled,
            'password_cycled'       => $passwordCycled,
            'effective'             => $effective,
            'notes'                 => $notes,
        ];
    }

    /**
     * Null means "no count available" — either the driver keeps no rows, or the
     * delete could not run. Never conflated with a genuine zero.
     */
    private function deleteSessionRows(Model $user, array &$notes): ?int
    {
        $driver = (string) config('session.driver');

        if ($driver !== 'database') {
            $notes[] = "session driver [{$driver}] keeps no server-side session record, "
                . 'so there are no rows to delete';

            return null;
        }

        $connection = config('session.connection');
        $table = (string) (config('session.table') ?: 'sessions');

        try {
            return DB::connection($connection)->table($table)
                ->where('user_id', $user->getKey())
                ->delete();
        } catch (\Throwable $e) {
            $notes[] = "could not clear session table [{$table}]: " . $e->getMessage();

            return null;
        }
    }

    /**
     * The driver-agnostic half: mark everything issued before now as invalid.
     */
    private function stampRevocation(Model $user, array &$notes): bool
    {
        if (!config('quadsso.sessions.revocation', true)) {
            $notes[] = 'session revocation is disabled in configuration';

            return false;
        }

        $field = (string) config('quadsso.sessions.revoked_at_field', 'quadsso_sessions_valid_after');

        if (!$this->modelHasColumn($user, $field)) {
            $notes[] = "revocation column [{$field}] does not exist on the users table; "
                . 'run the QuadSSO migrations';

            return false;
        }

        $user->{$field} = now();
        $user->save();

        return true;
    }

    /**
     * Loaded attributes first, since a model fetched with `first()` carries
     * every column. A freshly created model does not — it holds only what was
     * assigned — so fall back to the schema. That is a query, but revocation is
     * a rare operation, unlike the per-request checks elsewhere in this package.
     */
    private function modelHasColumn(Model $user, string $column): bool
    {
        if (array_key_exists($column, $user->getAttributes())) {
            return true;
        }

        try {
            return $user->getConnection()
                ->getSchemaBuilder()
                ->hasColumn($user->getTable(), $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function cycleRememberToken(Model $user): bool
    {
        if (!method_exists($user, 'setRememberToken')) {
            return false;
        }

        $user->setRememberToken(Str::random(60));
        $user->save();

        return true;
    }

    private function cyclePassword(Model $user, array &$notes): bool
    {
        if (!config('quadsso.sessions.cycle_password_on_revoke', false)) {
            return false;
        }

        $field = method_exists($user, 'getAuthPasswordName') ? $user->getAuthPasswordName() : 'password';

        if (!$this->modelHasColumn($user, $field)) {
            $notes[] = "cannot cycle password: column [{$field}] is not present on the model";

            return false;
        }

        $user->{$field} = Hash::make(Str::random(40));
        $user->save();

        return true;
    }
}
