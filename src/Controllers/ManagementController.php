<?php

namespace QuadCompanies\QuadSSO\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use QuadCompanies\QuadSSO\Contracts\UserLifecycleHooks;
use QuadCompanies\QuadSSO\Support\NullUserLifecycleHooks;

/**
 * Out-of-band account management for callers that hold the management API key.
 *
 * Two actions, both keyed on email address:
 *
 *   SUSPEND — terminate every session and mark the account blocked
 *   DELETE  — the same, then remove the row
 *
 * Each wraps optional application hooks (see UserLifecycleHooks): a `before`
 * hook that can veto by throwing, and an `after` hook for follow-up work.
 */
class ManagementController extends Controller
{
    private const ACTION_SUSPEND = 'SUSPEND';
    private const ACTION_DELETE = 'DELETE';

    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'action' => ['required', 'string'],
            'email'  => ['required', 'string', 'email'],
        ]);

        if ($validator->fails()) {
            return $this->error('Invalid request.', 422, ['errors' => $validator->errors()->toArray()]);
        }

        $action = strtoupper(trim((string) $request->input('action')));
        $email = (string) $request->input('email');

        if (!in_array($action, [self::ACTION_SUSPEND, self::ACTION_DELETE], true)) {
            return $this->error('Unsupported action. Expected SUSPEND or DELETE.', 422);
        }

        $userModel = config('quadsso.user_model', \App\Models\User::class);
        $emailField = config('quadsso.field_mappings.email', 'email');

        $user = $userModel::where($emailField, $email)->first();

        if (!$user) {
            return $this->error('No user found for that email address.', 404);
        }

        try {
            $hooks = $this->resolveHooks();
        } catch (\Throwable $e) {
            Log::error('QuadSSO management API: hooks class could not be resolved', [
                'error' => $e->getMessage(),
            ]);

            return $this->error('Configured lifecycle hooks could not be resolved.', 500);
        }

        return $action === self::ACTION_SUSPEND
            ? $this->suspend($user, $hooks)
            : $this->delete($user, $hooks);
    }

    private function suspend(Model $user, UserLifecycleHooks $hooks): JsonResponse
    {
        if ($veto = $this->runBefore(fn() => $hooks->beforeSuspend($user), 'beforeSuspend', $user)) {
            return $veto;
        }

        $sessions = null;

        DB::transaction(function () use ($user, &$sessions) {
            $sessions = $this->terminateSessions($user);
            $this->markBlocked($user);
        });

        $postHookFailed = $this->runAfter(fn() => $hooks->afterSuspend($user), 'afterSuspend', $user);

        Log::info('QuadSSO management API: user suspended', [
            'user_id'  => $user->getKey(),
            'sessions' => $sessions,
        ]);

        return $this->ok('SUSPEND', $user, $sessions, $postHookFailed);
    }

    private function delete(Model $user, UserLifecycleHooks $hooks): JsonResponse
    {
        if ($veto = $this->runBefore(fn() => $hooks->beforeDelete($user), 'beforeDelete', $user)) {
            return $veto;
        }

        $sessions = null;
        $userId = $user->getKey();

        DB::transaction(function () use ($user, &$sessions) {
            $sessions = $this->terminateSessions($user);

            // Blocked first, so that a soft-deleting model left recoverable is
            // still refused at login if it is ever restored.
            $this->markBlocked($user);

            $forceDelete = config('quadsso.management.force_delete', true);

            if ($forceDelete && method_exists($user, 'forceDelete')) {
                $user->forceDelete();

                return;
            }

            $user->delete();
        });

        $postHookFailed = $this->runAfter(fn() => $hooks->afterDelete($user), 'afterDelete', $user);

        Log::info('QuadSSO management API: user deleted', [
            'user_id'  => $userId,
            'sessions' => $sessions,
        ]);

        return $this->ok('DELETE', $user, $sessions, $postHookFailed);
    }

    /**
     * Terminate every database-backed session for this user.
     *
     * Tolerates a missing sessions table: an application on the file or redis
     * session driver still wants suspension to deactivate the account, and
     * failing the whole request over a table it never had would be unhelpful.
     * Returns null when the count could not be determined.
     */
    private function terminateSessions(Model $user): ?int
    {
        $deleted = null;

        try {
            $deleted = DB::table('sessions')->where('user_id', $user->getKey())->delete();
        } catch (\Throwable $e) {
            Log::warning('QuadSSO management API: could not clear sessions table', [
                'user_id' => $user->getKey(),
                'error'   => $e->getMessage(),
            ]);
        }

        // Remember-me cookies outlive session rows, so cycling the token is part
        // of ending a session, not an extra.
        if (method_exists($user, 'setRememberToken')) {
            $user->setRememberToken(Str::random(60));
            $user->save();
        }

        return $deleted;
    }

    private function markBlocked(Model $user): void
    {
        $statusField = config('quadsso.provisioning.user_status_field', 'status');
        $blockedValue = config('quadsso.provisioning.blocked_status_value', 'blocked');

        $user->{$statusField} = $blockedValue;
        $user->save();
    }

    /**
     * A throwing `before` hook vetoes the operation; nothing has been written yet.
     */
    private function runBefore(callable $hook, string $name, Model $user): ?JsonResponse
    {
        try {
            $hook();

            return null;
        } catch (\Throwable $e) {
            Log::warning('QuadSSO management API: operation vetoed by lifecycle hook', [
                'hook'    => $name,
                'user_id' => $user->getKey(),
                'error'   => $e->getMessage(),
            ]);

            return $this->error('Operation refused by application hook: ' . $e->getMessage(), 409);
        }
    }

    /**
     * An `after` hook cannot roll anything back — the change is committed. Report
     * the failure instead of pretending the whole request failed, so the caller
     * does not retry an action that already took effect.
     */
    private function runAfter(callable $hook, string $name, Model $user): bool
    {
        try {
            $hook();

            return false;
        } catch (\Throwable $e) {
            Log::error('QuadSSO management API: post hook failed after the change was committed', [
                'hook'    => $name,
                'user_id' => $user->getKey(),
                'error'   => $e->getMessage(),
            ]);

            return true;
        }
    }

    private function resolveHooks(): UserLifecycleHooks
    {
        $class = config('quadsso.management.hooks');

        if (!$class) {
            return new NullUserLifecycleHooks();
        }

        $hooks = app()->make($class);

        if (!$hooks instanceof UserLifecycleHooks) {
            throw new \RuntimeException(
                "[$class] must implement " . UserLifecycleHooks::class
                . '; extending ' . NullUserLifecycleHooks::class . ' is the easiest way.'
            );
        }

        return $hooks;
    }

    private function ok(string $action, Model $user, ?int $sessions, bool $postHookFailed): JsonResponse
    {
        return response()->json([
            'status'           => 'ok',
            'action'           => $action,
            'user_id'          => $user->getKey(),
            'sessions_cleared' => $sessions,
            'post_hook_failed' => $postHookFailed,
        ]);
    }

    private function error(string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'status'  => 'error',
            'message' => $message,
        ], $extra), $status);
    }
}
