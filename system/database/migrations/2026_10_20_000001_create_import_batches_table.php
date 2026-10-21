<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Downtime import (Step 6j): one row per file Admin imported. The visits
     * it created point back to it (visits.import_batch_id), so the whole file
     * can be undone in one step. Patients created only by the file point to it
     * too (patients.import_batch_id), so "Undo" knows which ones to move to Trash.
     */
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('file_name', 255);
            $table->unsignedInteger('original_rows');
            $table->unsignedInteger('imported_count');
            $table->unsignedInteger('skipped_count');
            $table->foreignId('imported_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent()->index();
            $table->timestamp('undone_at')->nullable();
            $table->foreignId('undone_by')->nullable()->constrained('users');
        });

        Schema::table('visits', function (Blueprint $table) {
            // Who wrote the row on paper / in the downtime sheet. Shown in Records.
            $table->string('written_by', 60)->nullable()->after('remarks');
            $table->foreignId('import_batch_id')->nullable()->after('written_by')->constrained('import_batches');
            $table->index('import_batch_id');
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->foreignId('import_batch_id')->nullable()->constrained('import_batches');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_batch_id');
        });

        Schema::table('visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_batch_id');
            $table->dropColumn('written_by');
        });

        Schema::dropIfExists('import_batches');
    }
};
