<?php

namespace QuadCompanies\QuadSSO\Tests\Fixtures;

/**
 * Exposes status through a classic accessor instead of a column, to prove the
 * fail-closed check recognises a computed status rather than treating it as a
 * misconfiguration.
 */
class AccessorStatusUser extends User
{
    public function getDerivedStatusAttribute(): string
    {
        return 'active';
    }
}
