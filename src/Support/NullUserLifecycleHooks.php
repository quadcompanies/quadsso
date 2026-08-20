<?php

namespace QuadCompanies\QuadSSO\Support;

use Illuminate\Database\Eloquent\Model;
use QuadCompanies\QuadSSO\Contracts\UserLifecycleHooks;

/**
 * No-op base. Extend it and override only the hooks you care about, rather than
 * implementing the full interface every time.
 *
 *   class RevokeLicences extends NullUserLifecycleHooks
 *   {
 *       public function beforeDelete(Model $user): void { ... }
 *   }
 */
class NullUserLifecycleHooks implements UserLifecycleHooks
{
    public function beforeSuspend(Model $user): void
    {
    }

    public function afterSuspend(Model $user): void
    {
    }

    public function beforeUnsuspend(Model $user): void
    {
    }

    public function afterUnsuspend(Model $user): void
    {
    }

    public function beforeDelete(Model $user): void
    {
    }

    public function afterDelete(Model $user): void
    {
    }
}
