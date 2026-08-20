<?php

namespace QuadCompanies\QuadSSO\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Application code invoked around suspend and delete performed through the
 * management API.
 *
 * Resolved from the container, so constructor injection works.
 *
 * Throwing from a `before` hook ABORTS the operation — nothing is written and
 * the API responds 409. That is the supported way to veto a request (an open
 * invoice, a legal hold, a protected account).
 *
 * Throwing from an `after` hook does NOT roll anything back: the change is
 * already committed by then. The exception is caught, logged, and reported in
 * the response as a failed post hook so the caller knows follow-up work did not
 * finish.
 */
interface UserLifecycleHooks
{
    public function beforeSuspend(Model $user): void;

    public function afterSuspend(Model $user): void;

    public function beforeUnsuspend(Model $user): void;

    public function afterUnsuspend(Model $user): void;

    public function beforeDelete(Model $user): void;

    /**
     * The row is gone by the time this runs. The model still carries its
     * attributes in memory, but it is detached — reloading it will find nothing.
     */
    public function afterDelete(Model $user): void;
}
