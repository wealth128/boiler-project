<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which months are closed (locked). A closed month blocks all edits and
     * deletes of its visits until Admin reopens it.
     */
    public function up(): void
    {
        Schema::create('month_closures', function (Blueprint $table) {
            $table->id();
            $table->char('period', 7)->unique(); // e.g. 2026-09
            $table->boolean('is_closed')->default(true);
            $table->foreignId('closed_by')->constrained('users');
            $table->dateTime('closed_at');
            $table->foreignId('reopened_by')->nullable()->constrained('users');
            $table->dateTime('reopened_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('month_closures');
    }
};
