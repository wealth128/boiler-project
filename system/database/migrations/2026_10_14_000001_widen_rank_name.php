<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rank names may be 2 to 60 characters (Dropdown Lists, Step 6c), the
     * same length as visits.rank_other. Was 40.
     */
    public function up(): void
    {
        Schema::table('ranks', function (Blueprint $table) {
            $table->string('name', 60)->change();
        });
    }

    public function down(): void
    {
        Schema::table('ranks', function (Blueprint $table) {
            $table->string('name', 40)->change();
        });
    }
};
