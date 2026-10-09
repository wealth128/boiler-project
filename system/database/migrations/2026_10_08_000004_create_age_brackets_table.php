<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Age brackets for reports. Not linked to visits by key: reports place
     * each visit's age into a bracket when the report runs.
     * The app (not the database) blocks overlapping brackets.
     */
    public function up(): void
    {
        Schema::create('age_brackets', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('min_age');
            $table->unsignedTinyInteger('max_age')->nullable(); // null = "and above"
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users');
            $table->string('delete_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('age_brackets');
    }
};
