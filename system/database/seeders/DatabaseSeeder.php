<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeds the starting data. Safe to run again: existing rows are kept, and
 * values that Admin may have edited (passwords, settings) are not overwritten.
 *
 * Model events stay ON (no WithoutModelEvents): they generate the Patient ID
 * and Visit No. for any patients or visits a seeder creates.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AccountSeeder::class,
            BranchAndRankSeeder::class,
            DiagnosisSeeder::class,
            AgeBracketSeeder::class,
            SettingSeeder::class,
        ]);
    }
}
