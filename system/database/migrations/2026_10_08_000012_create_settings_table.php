<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Key/value settings: hospital name, logo, report signatories.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 50)->primary();
            $table->text('value'); // empty string when not set yet
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
