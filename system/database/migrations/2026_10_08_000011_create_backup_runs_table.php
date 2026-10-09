<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Result of each automatic or manual backup. Failures show on the Admin screen.
     */
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('started_at');
            $table->dateTime('finished_at');
            $table->enum('status', ['success', 'failed']);
            // Nullable because a failed run may produce no file.
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('message')->nullable(); // error detail

            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
