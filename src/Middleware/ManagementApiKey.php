<?php

namespace QuadCompanies\QuadSSO\Middleware;

use Closure;
use Illuminate\Http\Request;
use QuadCompanies\QuadSSO\Support\QuadSsoLog;

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
        QuadSsoLog::trace(QuadSsoLog::API, 'management API hit', [
            'ip'     => $request->ip(),
            'path'   => $request->path(),
            'method' => $request->method(),
            'agent'  => $request->userAgent(),
        ]);

        $expected = (string) config('quadsso.management.api_key', '');

        if ($expected === '') {
            QuadSsoLog::error('management API refusing all requests: no api_key configured');

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
            QuadSsoLog::warning('management API rejected a request: invalid or missing key', [
                'ip'    => $request->ip(),
                'path'  => $request->path(),
                'agent' => $request->userAgent(),
            ]);

            return $this->error('Unauthorized.', 401);
        }

        QuadSsoLog::trace(QuadSsoLog::API, 'management API request authorised', [
            'ip' => $request->ip(),
        ]);

        return $next($request);
    }

    private function error(string $message, int $status)
    {
        return response()->json(['status' => 'error', 'message' => $message], $status);
    }
}
