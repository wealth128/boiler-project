<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each backup now also zips the picture files (logo, signatures). The
     * zip's name and size are kept next to the database dump's. Runs made
     * before this change have no zip, so both columns are nullable.
     */
    public function up(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->string('zip_name')->nullable()->after('size_bytes');
            $table->unsignedBigInteger('zip_size_bytes')->nullable()->after('zip_name');
        });
    }

    public function down(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->dropColumn(['zip_name', 'zip_size_bytes']);
        });
    }
};
