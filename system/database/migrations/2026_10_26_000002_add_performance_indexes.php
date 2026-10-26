<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the lists that page through the whole table (Step 7g). Each one
 * starts with deleted_at, because every list either hides Trash
 * (deleted_at IS NULL) or shows only Trash (deleted_at IS NOT NULL), and
 * continues with the column the list is sorted by, so MySQL reads the rows
 * already in order and stops after one page.
 *
 * - visits (deleted_at, visited_at): Records > Visits (newest first, date
 *   range), its late pages, and the visits part of Trash.
 * - patients (deleted_at, last_name, first_name): Records > Patients (by
 *   name), its late pages, and the patients part of Trash.
 * - audit_logs (action, created_at): the Action filter of the Audit log and
 *   the "deleted permanently" part of the Record filter.
 *
 * Adding them changes no data. Only new indexes: nothing is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->index(['deleted_at', 'visited_at'], 'visits_deleted_at_visited_at_index');
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->index(['deleted_at', 'last_name', 'first_name'], 'patients_deleted_at_name_index');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['action', 'created_at'], 'audit_logs_action_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropIndex('visits_deleted_at_visited_at_index');
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex('patients_deleted_at_name_index');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_action_created_at_index');
        });
    }
};
