<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Delete month report": Admin moves all visits of one month to Trash in
     * a single action. This row is that single Trash entry; the visits point
     * to it through visits.month_deletion_id.
     *  - Restore: restore the visits, then delete this row.
     *  - Delete permanently: force-delete the visits, then delete this row.
     *
     * Listed in planning/phase5-foundation.md but not yet defined in
     * planning/database-schema.md; columns follow the prototype's Trash entry.
     */
    public function up(): void
    {
        Schema::create('month_deletions', function (Blueprint $table) {
            $table->id();
            $table->char('period', 7)->index(); // e.g. 2026-09
            $table->unsignedInteger('visit_count');
            $table->foreignId('deleted_by')->constrained('users');
            $table->string('delete_reason');
            $table->dateTime('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('month_deletions');
    }
};
