<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the column that makes session revocation work on any session driver.
     *
     * Deleting session rows only ends a session when the driver keeps rows. On
     * the `cookie` driver the session lives in the client's cookie and the
     * server holds no record of it, so there is nothing to delete.
     *
     * This column records the moment every session issued before it became
     * invalid. Middleware compares it against the session's own establishment
     * time and logs out anything older — which works regardless of where the
     * session is stored.
     *
     * Null by default, so installing this logs nobody out. Sessions predating
     * the upgrade become revocable the first time a user is suspended.
     */
    public function up(): void
    {
        $field = config('quadsso.sessions.revoked_at_field', 'quadsso_sessions_valid_after');

        if (Schema::hasColumn('users', $field)) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($field) {
            $table->timestamp($field)->nullable();
        });
    }

    public function down(): void
    {
        $field = config('quadsso.sessions.revoked_at_field', 'quadsso_sessions_valid_after');

        if (!Schema::hasColumn('users', $field)) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($field) {
            $table->dropColumn($field);
        });
    }
};
