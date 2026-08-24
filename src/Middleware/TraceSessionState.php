<?php

namespace QuadCompanies\QuadSSO\Middleware;

use Closure;
use Illuminate\Http\Request;
use QuadCompanies\QuadSSO\Support\QuadSsoLog;

/**
 * Names every request that touches the OAuth state, for the case where the state
 * is written to the session store and is gone again before the callback reads it.
 *
 * By that point the useful questions are no longer about the SSO flow — the
 * redirect leg stored the state, the store accepted it, the cookie came back and
 * the identity provider echoed the right nonce. The only remaining question is
 * which *other* request removed it, and nothing in the login flow can see that
 * because it happens in a different request entirely.
 *
 * So this sits on the web group and reports, for every request, whether the state
 * was present when the request began and whether it was still there when the
 * request finished. The callback consuming it is expected and appears here as
 * well; anything else consuming it is the bug.
 *
 * Debugging only, and off by default. It logs a line per request, which is
 * unreadable in production and exactly what you want while reproducing a fault
 * on a development machine.
 *
 * One honest limit: this observes the session *object* within a request. A
 * concurrent request that loaded the session earlier and saved a stale copy over
 * the top destroys the state without ever holding it, so it shows up here as a
 * request that never had it. The method, path and timing are what identify it.
 */
class TraceSessionState
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (!config('quadsso.logging.session_watchdog', false) || !$request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();

        $before = $session->get('state');
        $idBefore = $session->getId();

        $response = $next($request);

        $after = $session->get('state');
        $idAfter = $session->getId();

        $context = [
            'method'           => $request->getMethod(),
            'path'             => '/' . ltrim($request->path(), '/'),
            'ajax'             => $request->ajax(),
            'session_id'       => $idBefore,
            'state_on_entry'   => $this->fingerprint($before),
            'state_on_exit'    => $this->fingerprint($after),
            // The line to grep for. Every true here is a request that took the
            // state away; exactly one of them should be the SSO callback.
            'state_consumed'   => $before !== null && $after === null,
            'state_introduced' => $before === null && $after !== null,
        ];

        if ($idBefore !== $idAfter) {
            // Regeneration orphans whatever the old id held, which looks identical
            // to a lost state from the callback's point of view.
            $context['session_id_after'] = $idAfter;
        }

        QuadSsoLog::trace(QuadSsoLog::SSO, 'session state observed across a request', $context);

        return $response;
    }

    private function fingerprint($value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return substr(hash('sha256', $value), 0, 8);
    }
}
