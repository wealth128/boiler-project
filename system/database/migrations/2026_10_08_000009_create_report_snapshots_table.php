<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saved copy of a month's report, taken when Admin closes the month.
     * One current copy per month: discarded on reopen, saved fresh on close.
     */
    public function up(): void
    {
        Schema::create('report_snapshots', function (Blueprint $table) {
            $table->id();
            $table->char('period', 7)->unique(); // e.g. 2026-09
            $table->json('data');
            $table->foreignId('created_by')->constrained('users');
            $table->dateTime('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_snapshots');
    }
};
