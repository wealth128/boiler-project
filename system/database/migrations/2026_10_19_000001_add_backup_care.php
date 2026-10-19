<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step 6i-3: the automatic test restore and the Copy to USB button.
     *
     * - backup_runs keeps the record counts seen when the backup was made, so
     *   the test restore can compare them with what the restored copy holds.
     * - backup_marks.marked_by may be empty: an automatic test restore is
     *   marked by "System (automatic)", not by a person.
     * - test_restore_runs keeps every test restore, good or failed, so a
     *   failure shows as a red bar until a later one succeeds.
     */
    public function up(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->unsignedInteger('patients_count')->nullable()->after('zip_size_bytes');
            $table->unsignedInteger('visits_count')->nullable()->after('patients_count');
            $table->unsignedInteger('users_count')->nullable()->after('visits_count');
            $table->unsignedInteger('closed_months_count')->nullable()->after('users_count');
        });

        Schema::table('backup_marks', function (Blueprint $table) {
            $table->dropForeign(['marked_by']);
            $table->unsignedBigInteger('marked_by')->nullable()->change();
            $table->foreign('marked_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('test_restore_runs', function (Blueprint $table) {
            $table->id();
            $table->enum('trigger', ['automatic', 'manual']);
            $table->enum('status', ['success', 'failed']);
            $table->foreignId('backup_run_id')->nullable()->constrained('backup_runs')->nullOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('finished_at');
            $table->text('message')->nullable();

            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_restore_runs');

        Schema::table('backup_marks', function (Blueprint $table) {
            $table->dropForeign(['marked_by']);
            $table->unsignedBigInteger('marked_by')->nullable(false)->change();
            $table->foreign('marked_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('backup_runs', function (Blueprint $table) {
            $table->dropColumn(['patients_count', 'visits_count', 'users_count', 'closed_months_count']);
        });
    }
};
