<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration is OPTIONAL. Only run it if you want to use separate
     * name fields (first, last, middle) instead of Laravel's default single 'name' field.
     *
     * To use these fields:
     * 1. Run this migration
     * 2. Update config/quadsso.php field_mappings to enable them:
     *    'name_first' => 'name_first',
     *    'name_last' => 'name_last',
     *    'name_middle' => 'name_middle',
     *    'phone_cell' => 'phone_cell',
     *    'email_secondary' => 'email_secondary',
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Extended name fields (alternative to single 'name' column)
            if (!Schema::hasColumn('users', 'name_first')) {
                $table->string('name_first')->nullable()->after('name');
            }

            if (!Schema::hasColumn('users', 'name_last')) {
                $table->string('name_last')->nullable()->after('name_first');
            }

            if (!Schema::hasColumn('users', 'name_middle')) {
                $table->string('name_middle')->nullable()->after('name_last');
            }

            // Contact fields
            if (!Schema::hasColumn('users', 'phone_cell')) {
                $table->string('phone_cell')->nullable()->after('email');
            }

            if (!Schema::hasColumn('users', 'email_secondary')) {
                $table->string('email_secondary')->nullable()->after('email');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columnsToRemove = ['name_first', 'name_last', 'name_middle', 'phone_cell', 'email_secondary'];

            foreach ($columnsToRemove as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
