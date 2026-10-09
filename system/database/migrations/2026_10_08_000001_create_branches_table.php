<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Branches of service: Army, Navy, Air Force, Marines, Others.
     * Not soft-deletable; switch a branch off with is_active instead.
     */
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name', 30)->unique();
            $table->boolean('is_afp')->default(true); // false for "Others" (shown as "Type", not "Rank")
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
