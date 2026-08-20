<?php

namespace QuadCompanies\QuadSSO\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Shared-secret guard for the management API.
 *
 * The endpoint suspends and deletes accounts, so it fails closed: with no key
 * configured it answers 503 rather than serving anything.
 */
class ManagementApiKey
{
    public function handle(Request $request, Closure $next): mixed
    {
        $expected = (string) config('quadsso.management.api_key', '');

        if ($expected === '') {
            Log::error('QuadSSO management API: no api_key configured, refusing all requests');

            return $this->error('Management API key is not configured.', 503);
        }

        $header = (string) config('quadsso.management.header', 'X-QuadSSO-Key');
        $provided = (string) ($request->header($header) ?? '');

        // Also accept a bearer token, so callers that only speak Authorization
        // headers don't need a bespoke client.
        if ($provided === '') {
            $provided = (string) ($request->bearerToken() ?? '');
        }

        if ($provided === '' || !hash_equals($expected, $provided)) {
            Log::warning('QuadSSO management API: rejected request', [
                'ip'   => $request->ip(),
                'path' => $request->path(),
            ]);

            return $this->error('Unauthorized.', 401);
        }

        return $next($request);
    }

    private function error(string $message, int $status)
    {
        return response()->json(['status' => 'error', 'message' => $message], $status);
    }
}
