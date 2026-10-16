<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin's manual backup safety steps: the weekly USB copy and the monthly
     * test restore. A reminder only: nothing is ever blocked by these rows.
     */
    public function up(): void
    {
        Schema::create('backup_marks', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['usb_copy', 'test_restore']);
            $table->foreignId('marked_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('marked_at');

            $table->index(['type', 'marked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_marks');
    }
};
