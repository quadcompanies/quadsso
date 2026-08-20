<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // External IdP subject identifier (the OIDC `sub`; an Authentik UUID).
            // Named scim_external_id for backwards compatibility.
            if (!Schema::hasColumn('users', 'scim_external_id')) {
                $table->string('scim_external_id')->nullable()->unique()->after('email');
            }

            // Add email_verified_at if it doesn't exist (standard Laravel field)
            if (!Schema::hasColumn('users', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable()->after('email');
            }

            // Account status, read by the SSO callback to deny blocked users
            if (!Schema::hasColumn('users', 'status')) {
                $table->string('status')->default('active')->after('email');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'scim_external_id')) {
                $table->dropColumn('scim_external_id');
            }

            // Note: We don't drop status or email_verified_at as they may be used by other parts of the app
        });
    }
};
