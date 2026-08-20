<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the user level/role column QuadSSO writes to.
     *
     * The model observer registered by QuadSSOServiceProvider sets a default
     * level on every user it creates (see 'provisioning.default_user_level', which is
     * 'user' out of the box). That column is not part of Laravel's standard
     * users table, so without it user creation fails with "column not found".
     *
     * The column name follows 'provisioning.user_level_field', so pointing that config
     * at an existing column in your schema makes this migration a no-op.
     * Setting 'provisioning.default_user_level' to an empty value disables the observer
     * behaviour entirely — this migration then still creates the column, which
     * is harmless, but you can safely skip it.
     */
    public function up(): void
    {
        $levelField = config('quadsso.provisioning.user_level_field', 'level');
        $defaultLevel = config('quadsso.provisioning.default_user_level', 'user');

        if (Schema::hasColumn('users', $levelField)) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($levelField, $defaultLevel) {
            $column = $table->string($levelField)->nullable();

            if (!empty($defaultLevel)) {
                $column->default($defaultLevel);
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * Intentionally a no-op. A level/role column is commonly shared with the
     * host application's own authorization logic, and this migration only ever
     * creates it when absent — so it cannot tell whether it owns the column.
     * Dropping it on rollback risks destroying application data. Same reasoning
     * as 'status' in the base migration. Drop it by hand if you need to.
     */
    public function down(): void
    {
        //
    }
};
