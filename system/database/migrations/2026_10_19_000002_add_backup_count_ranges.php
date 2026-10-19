<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A backup now counts patients, visits, users and closed months before and
     * after the dump, because mysqldump reads one snapshot in between. The
     * *_count columns hold the lowest number and these hold the highest. The
     * test restore accepts any restored number between the two.
     */
    public function up(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->unsignedInteger('patients_count_max')->nullable()->after('patients_count');
            $table->unsignedInteger('visits_count_max')->nullable()->after('visits_count');
            $table->unsignedInteger('users_count_max')->nullable()->after('users_count');
            $table->unsignedInteger('closed_months_count_max')->nullable()->after('closed_months_count');
        });
    }

    public function down(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->dropColumn(['patients_count_max', 'visits_count_max', 'users_count_max', 'closed_months_count_max']);
        });
    }
};
