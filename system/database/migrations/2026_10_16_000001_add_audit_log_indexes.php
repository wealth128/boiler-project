<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Audit log screen sorts by date and filters by user and date.
     * created_at is already indexed by the create migration.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            if (! Schema::hasIndex('audit_logs', ['created_at'])) {
                $table->index('created_at');
            }

            if (! Schema::hasIndex('audit_logs', ['user_id', 'created_at'])) {
                $table->index(['user_id', 'created_at']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            if (Schema::hasIndex('audit_logs', ['user_id', 'created_at'])) {
                $table->dropIndex(['user_id', 'created_at']);
            }
        });
    }
};
