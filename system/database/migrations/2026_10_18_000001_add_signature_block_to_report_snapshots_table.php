<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The signature block as it was when the month was closed: the Prepared by
     * and Noted by names and titles, and the file names of the two signature
     * pictures copied to that month's private folder. Null for a month closed
     * before this column existed (it then uses the current settings).
     * Removed together with the snapshot when the month is reopened.
     */
    public function up(): void
    {
        Schema::table('report_snapshots', function (Blueprint $table) {
            $table->json('signature_block')->nullable()->after('data');
        });
    }

    public function down(): void
    {
        Schema::table('report_snapshots', function (Blueprint $table) {
            $table->dropColumn('signature_block');
        });
    }
};
