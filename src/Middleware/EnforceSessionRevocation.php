<?php

namespace QuadCompanies\QuadSSO\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use QuadCompanies\QuadSSO\Support\QuadSsoLog;

/**
 * Rejects sessions issued before the user's revocation timestamp.
 *
 * This is what actually ends a session on a stateless driver. When the session
 * lives in the client's cookie there is nothing on the server to delete, so the
 * only way to end it is to refuse it on its next request.
 *
 * Costs no extra queries: SessionGuard::user() already loads the user row from
 * the database on every authenticated request, so the revocation timestamp
 * arrives with it.
 *
 * A session with no recorded establishment time is treated as older than any
 * revocation — so sessions predating the upgrade, and sessions created by the
 * host application's own login, are revoked too. Nobody is logged out until a
 * revocation is actually stamped, because the column is null until then.
 */
class EnforceSessionRevocation
{
    public const SESSION_KEY = 'quadsso_authenticated_at';

    public function handle(Request $request, Closure $next): mixed
    {
        if (!config('quadsso.sessions.revocation', true) || !$request->hasSession()) {
            return $next($request);
        }

        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        $revokedAt = $this->revokedAt($user);

        if ($revokedAt === null) {
            return $next($request);
        }

        $establishedAt = (int) $request->session()->get(self::SESSION_KEY, 0);

        if ($establishedAt >= $revokedAt) {
            return $next($request);
        }

        QuadSsoLog::warning('session rejected: issued before the account was revoked', [
            'user_id'        => $user->getKey(),
            'established_at' => $establishedAt,
            'revoked_at'     => $revokedAt,
        ]);

        Auth::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Your session has been ended. Please sign in again.',
            ], 401);
        }

        return redirect(config('quadsso.sso.redirect_after_failure', '/login'))
            ->withErrors(['email' => 'Your session has been ended. Please sign in again.']);
    }

    /**
     * The column may be cast to a date by the host model or come back as a raw
     * string, so normalise both to a timestamp. A model without the column at
     * all returns null and the check is skipped — `quadsso:doctor` reports that
     * case rather than leaving it to be discovered in production.
     */
    private function revokedAt($user): ?int
    {
        $field = (string) config('quadsso.sessions.revoked_at_field', 'quadsso_sessions_valid_after');

        $value = $user->{$field} ?? null;

        if (empty($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        $parsed = strtotime((string) $value);

        return $parsed === false ? null : $parsed;
    }
}
