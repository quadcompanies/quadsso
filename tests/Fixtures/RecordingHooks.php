<?php

namespace QuadCompanies\QuadSSO\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use QuadCompanies\QuadSSO\Support\NullUserLifecycleHooks;

/**
 * Records call order, and can be told to throw from any hook so the veto and
 * post-hook-failure paths are exercised.
 */
class RecordingHooks extends NullUserLifecycleHooks
{
    /** @var array<int,string> */
    public static array $calls = [];

    /** @var array<int,string> */
    public static array $throwFrom = [];

    /** Status observed at the moment each hook ran, to prove ordering. */
    public static array $statusAtCall = [];

    public static array $existedAtCall = [];

    public static function reset(): void
    {
        static::$calls = [];
        static::$throwFrom = [];
        static::$statusAtCall = [];
        static::$existedAtCall = [];
    }

    private function record(string $hook, Model $user): void
    {
        static::$calls[] = $hook;
        static::$statusAtCall[$hook] = $user->status;
        static::$existedAtCall[$hook] = User::query()->whereKey($user->getKey())->exists();

        if (in_array($hook, static::$throwFrom, true)) {
            throw new \RuntimeException("hook {$hook} refused");
        }
    }

    public function beforeSuspend(Model $user): void
    {
        $this->record('beforeSuspend', $user);
    }

    public function afterSuspend(Model $user): void
    {
        $this->record('afterSuspend', $user);
    }

    public function beforeDelete(Model $user): void
    {
        $this->record('beforeDelete', $user);
    }

    public function afterDelete(Model $user): void
    {
        $this->record('afterDelete', $user);
    }
}
