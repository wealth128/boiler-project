<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who did what, when. Rows are never updated or deleted.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Nullable only for actions with no signed-in user (e.g. the
            // scheduled backup). Every user action stores the user.
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('action', 40); // e.g. visit.created, month.closed, user.unlocked
            $table->string('subject_type', 50); // e.g. visits (morph map name)
            $table->unsignedBigInteger('subject_id');
            $table->json('changes')->nullable(); // old and new values for edits
            // Nullable only for actions run by the system (scheduler/CLI), which
            // have no PC address. Every browser action stores the IP.
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
