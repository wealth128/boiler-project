<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnoses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->boolean('is_other')->default(false); // the fixed "Other (specify)" entry
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users');
            $table->string('delete_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnoses');
    }
};
