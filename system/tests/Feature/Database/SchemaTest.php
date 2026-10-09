<?php

use Illuminate\Support\Facades\Schema;

/*
 * Every table and column from planning/database-schema.md exists.
 */

dataset('tables', [
    'users' => ['users', ['id', 'name', 'username', 'password', 'role', 'office', 'is_active', 'failed_attempts', 'locked_at', 'remember_token', 'created_at', 'updated_at', 'deleted_at', 'deleted_by', 'delete_reason']],
    'patients' => ['patients', ['id', 'patient_no', 'last_name', 'first_name', 'middle_initial', 'sex', 'birthdate', 'created_at', 'updated_at', 'deleted_at', 'deleted_by', 'delete_reason']],
    'visits' => ['visits', ['id', 'visit_no', 'patient_id', 'visited_at', 'age', 'branch_id', 'rank_id', 'rank_other', 'diagnosis_id', 'diagnosis_other', 'category', 'remarks', 'encoded_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at', 'deleted_by', 'delete_reason', 'month_deletion_id']],
    'branches' => ['branches', ['id', 'name', 'is_afp', 'sort_order', 'is_active']],
    'ranks' => ['ranks', ['id', 'branch_id', 'name', 'is_other', 'sort_order', 'deleted_at', 'deleted_by', 'delete_reason']],
    'diagnoses' => ['diagnoses', ['id', 'name', 'is_other', 'sort_order', 'deleted_at', 'deleted_by', 'delete_reason']],
    'age_brackets' => ['age_brackets', ['id', 'min_age', 'max_age', 'sort_order', 'deleted_at', 'deleted_by', 'delete_reason']],
    'month_closures' => ['month_closures', ['id', 'period', 'is_closed', 'closed_by', 'closed_at', 'reopened_by', 'reopened_at']],
    'month_deletions' => ['month_deletions', ['id', 'period', 'visit_count', 'deleted_by', 'delete_reason', 'deleted_at']],
    'report_snapshots' => ['report_snapshots', ['id', 'period', 'data', 'created_by', 'created_at']],
    'audit_logs' => ['audit_logs', ['id', 'user_id', 'action', 'subject_type', 'subject_id', 'changes', 'ip_address', 'created_at']],
    'backup_runs' => ['backup_runs', ['id', 'started_at', 'finished_at', 'status', 'file_name', 'size_bytes', 'message']],
    'settings' => ['settings', ['key', 'value']],
]);

it('has every planned table with its columns', function (string $table, array $columns) {
    expect(Schema::hasTable($table))->toBeTrue("Missing table {$table}");

    foreach ($columns as $column) {
        expect(Schema::hasColumn($table, $column))->toBeTrue("Missing column {$table}.{$column}");
    }
})->with('tables');

it('has the report and lookup indexes on visits', function () {
    $indexes = collect(Schema::getIndexes('visits'))->pluck('columns')->map(fn (array $c) => implode(',', $c));

    expect($indexes)
        ->toContain('category,visited_at,deleted_at')
        ->toContain('patient_id,visited_at')
        ->toContain('encoded_by,visited_at')
        ->toContain('visit_no');
});

it('indexes patients by name for search', function () {
    $indexes = collect(Schema::getIndexes('patients'))->pluck('columns')->map(fn (array $c) => implode(',', $c));

    expect($indexes)->toContain('last_name,first_name')->toContain('patient_no');
});

it('links visits to their lookups with foreign keys', function () {
    $foreign = collect(Schema::getForeignKeys('visits'))
        ->mapWithKeys(fn (array $fk) => [implode(',', $fk['columns']) => $fk['foreign_table']]);

    expect($foreign->all())->toMatchArray([
        'patient_id' => 'patients',
        'branch_id' => 'branches',
        'rank_id' => 'ranks',
        'diagnosis_id' => 'diagnoses',
        'encoded_by' => 'users',
        'updated_by' => 'users',
        'deleted_by' => 'users',
        'month_deletion_id' => 'month_deletions',
    ]);
});
