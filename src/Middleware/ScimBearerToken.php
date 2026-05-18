<?php

namespace QuadCompanies\QuadSSO\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ScimBearerToken
{
    /**
     * Handle an incoming SCIM request and validate the bearer token.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (config('quadsso.logging.scim_requests', false)) {
            Log::debug('QuadSSO SCIM request', [
                'method'   => $request->method(),
                'endpoint' => $request->fullUrl(),
                'body'     => $request->getContent(),
            ]);
        }

        $token = config('quadsso.scim.bearer_token');

        if (empty($token)) {
            abort(503, 'SCIM bearer token not configured.');
        }

        $provided = $request->bearerToken();

        if (!$provided || !hash_equals($token, $provided)) {
            return response()->json([
                'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
                'status'  => '401',
                'detail'  => 'Unauthorized',
            ], 401);
        }

        return $next($request);
    }
}
