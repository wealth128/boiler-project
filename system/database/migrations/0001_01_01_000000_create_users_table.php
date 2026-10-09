<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Extended from the starter kit (planning/database-schema.md, "users").
     * Login is by username. `email` came with the starter kit and is
     * dropped by 2026_10_09_000001_drop_email_from_users_table (Step 3).
     * No email verification and no password reset by email, so there is no
     * `email_verified_at` column and no `password_reset_tokens` table.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('username', 50)->unique();
            $table->string('email')->nullable()->unique();
            $table->string('password');
            $table->enum('role', ['encoder', 'admin', 'system_admin', 'viewer']);
            $table->enum('office', ['ER', 'OPD', 'Admission', 'Admin', 'IT', 'Command']);
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('locked_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users');
            $table->string('delete_reason')->nullable();

            // Used by the "only one active Admin" check and user lists.
            $table->index(['role', 'is_active']);
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
