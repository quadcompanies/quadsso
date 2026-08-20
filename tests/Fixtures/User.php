<?php

namespace QuadCompanies\QuadSSO\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Deliberately mirrors the $fillable list the README tells integrators to use.
 * If the package ever writes a column that list doesn't cover, mass assignment
 * silently drops it and the provisioning tests fail — which is the point.
 */
class User extends Authenticatable
{
    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'scim_external_id',
        'email_verified_at',
        'status',
        'level',
        'name_first',
        'name_last',
        'name_middle',
        'phone_cell',
        'email_secondary',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['email_verified_at' => 'datetime'];
}
