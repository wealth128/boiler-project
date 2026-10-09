<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ranks per branch. The "Others" types (Dependent, Retiree, ...) live here too.
     */
    public function up(): void
    {
        Schema::create('ranks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('name', 40);
            $table->boolean('is_other')->default(false); // the fixed "Other (specify)" entry
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users');
            $table->string('delete_reason')->nullable();

            $table->unique(['branch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ranks');
    }
};
