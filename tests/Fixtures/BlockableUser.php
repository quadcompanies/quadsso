<?php

namespace QuadCompanies\QuadSSO\Tests\Fixtures;

/**
 * Exercises the isBlocked() escape hatch the SSO callback honours in addition
 * to the status column.
 */
class BlockableUser extends User
{
    public function isBlocked(): bool
    {
        return $this->email === 'blocked-by-method@example.test';
    }
}
