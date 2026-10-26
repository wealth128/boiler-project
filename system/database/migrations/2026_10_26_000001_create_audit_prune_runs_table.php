<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per run of the automatic audit log clean-up (census:prune-audit).
     * System health and the red Admin bar read the latest row.
     */
    public function up(): void
    {
        Schema::create('audit_prune_runs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('started_at');
            $table->dateTime('finished_at');
            $table->enum('status', ['success', 'failed']);
            $table->unsignedInteger('rows_count')->default(0);
            $table->string('archive_name')->nullable();
            $table->text('message')->nullable();

            $table->index(['started_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_prune_runs');
    }
};
