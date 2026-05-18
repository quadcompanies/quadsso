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
            // SCIM external ID (Authentik UUID)
            if (!Schema::hasColumn('users', 'scim_external_id')) {
                $table->string('scim_external_id')->nullable()->unique()->after('email');
            }

            // Add email_verified_at if it doesn't exist (standard Laravel field)
            if (!Schema::hasColumn('users', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable()->after('email');
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
        });
    }
};
