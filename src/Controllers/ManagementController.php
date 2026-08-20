<?php

namespace QuadCompanies\QuadSSO\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use QuadCompanies\QuadSSO\Contracts\UserLifecycleHooks;
use QuadCompanies\QuadSSO\Support\NullUserLifecycleHooks;
use QuadCompanies\QuadSSO\Support\QuadSsoLog;

/**
 * Out-of-band account management for callers that hold the management API key.
 *
 * Two actions, both keyed on email address:
 *
 *   SUSPEND   — terminate every session and mark the account blocked
 *   UNSUSPEND — lift the block so the account can sign in again
 *   DELETE    — SUSPEND, then remove the row
 *
 * Each wraps optional application hooks (see UserLifecycleHooks): a `before`
 * hook that can veto by throwing, and an `after` hook for follow-up work.
 */
class ManagementController extends Controller
{
    private const ACTION_SUSPEND = 'SUSPEND';
    private const ACTION_UNSUSPEND = 'UNSUSPEND';
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

        if (!in_array($action, [self::ACTION_SUSPEND, self::ACTION_UNSUSPEND, self::ACTION_DELETE], true)) {
            return $this->error('Unsupported action. Expected SUSPEND, UNSUSPEND or DELETE.', 422);
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
            QuadSsoLog::error('management API aborted: lifecycle hooks class could not be resolved', [
                'error' => $e->getMessage(),
            ]);

            return $this->error('Configured lifecycle hooks could not be resolved.', 500);
        }

        QuadSsoLog::trace(QuadSsoLog::API, 'management API action authorised', [
            'action'  => $action,
            'user_id' => $user->getKey(),
            'hooks'   => $hooks instanceof NullUserLifecycleHooks ? null : get_class($hooks),
        ]);

        return match ($action) {
            self::ACTION_SUSPEND   => $this->suspend($user, $hooks),
            self::ACTION_UNSUSPEND => $this->unsuspend($user, $hooks),
            default                => $this->delete($user, $hooks),
        };
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

        QuadSsoLog::trace(QuadSsoLog::API, 'management API completed SUSPEND', [
            'user_id'  => $user->getKey(),
            'sessions' => $sessions,
        ]);

        return $this->ok('SUSPEND', $user, $sessions, $postHookFailed);
    }

    /**
     * Lift a suspension.
     *
     * Sessions are not restored — they were destroyed, not parked — so the user
     * signs in again. The remember token stays cycled for the same reason: the
     * cookies that existed before the suspension are gone for good.
     */
    private function unsuspend(Model $user, UserLifecycleHooks $hooks): JsonResponse
    {
        if ($veto = $this->runBefore(fn() => $hooks->beforeUnsuspend($user), 'beforeUnsuspend', $user)) {
            return $veto;
        }

        DB::transaction(function () use ($user) {
            $statusField = config('quadsso.provisioning.user_status_field', 'status');
            $activeValue = config('quadsso.provisioning.active_status_value', 'active');

            $user->{$statusField} = $activeValue;
            $user->save();
        });

        $postHookFailed = $this->runAfter(fn() => $hooks->afterUnsuspend($user), 'afterUnsuspend', $user);

        QuadSsoLog::trace(QuadSsoLog::API, 'management API completed UNSUSPEND', [
            'user_id' => $user->getKey(),
        ]);

        return $this->ok('UNSUSPEND', $user, null, $postHookFailed);
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

        QuadSsoLog::trace(QuadSsoLog::API, 'management API completed DELETE', [
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
            QuadSsoLog::warning('management API could not clear the sessions table', [
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

            QuadSsoLog::trace(QuadSsoLog::API, 'lifecycle hook completed', [
                'hook'    => $name,
                'user_id' => $user->getKey(),
            ]);

            return null;
        } catch (\Throwable $e) {
            QuadSsoLog::warning('management API operation vetoed by a lifecycle hook', [
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

            QuadSsoLog::trace(QuadSsoLog::API, 'lifecycle hook completed', [
                'hook'    => $name,
                'user_id' => $user->getKey(),
            ]);

            return false;
        } catch (\Throwable $e) {
            QuadSsoLog::error('management API post hook failed after the change was committed', [
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
