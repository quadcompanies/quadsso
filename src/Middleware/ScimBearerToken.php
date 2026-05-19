<?php

namespace QuadCompanies\QuadSSO\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ScimBearerToken
{
    /**
     * SCIM attribute keys whose values may contain personally identifiable
     * information. When QUADSSO_LOG_SCIM_REQUESTS is enabled we log the
     * request body for debugging — but we don't want to dump verbatim PII
     * (emails, names, phone numbers, external_ids) into application logs.
     */
    private const PII_KEYS = [
        'userName', 'externalId', 'displayName', 'nickName', 'profileUrl', 'title',
        'preferredLanguage', 'locale', 'timezone',
        'name', 'givenName', 'familyName', 'middleName', 'formatted', 'honorificPrefix', 'honorificSuffix',
        'emails', 'phoneNumbers', 'ims', 'photos', 'addresses', 'value',
        'password',
    ];

    /**
     * Handle an incoming SCIM request and validate the bearer token.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (config('quadsso.logging.scim_requests', false)) {
            Log::debug('QuadSSO SCIM request', [
                'method'   => $request->method(),
                'endpoint' => $request->fullUrl(),
                'body'     => $this->redactBody($request->getContent()),
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

    /**
     * Replace PII values in a SCIM JSON body with `[redacted]` markers.
     * Non-JSON bodies become `[non-json body]`. Bodies that don't parse cleanly
     * are reported as `[unparseable body]` rather than logged raw.
     */
    private function redactBody(string $body): mixed
    {
        if ($body === '') {
            return '';
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            return '[non-json body]';
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            return '[unparseable body]';
        }

        return $this->walkAndRedact($data);
    }

    private function walkAndRedact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->walkAndRedact($value);
                continue;
            }

            if (is_string($value) && $value !== '' && $this->isPiiKey($key)) {
                $data[$key] = '[redacted]';
            }
        }

        return $data;
    }

    private function isPiiKey(int|string $key): bool
    {
        return is_string($key) && in_array($key, self::PII_KEYS, true);
    }
}
