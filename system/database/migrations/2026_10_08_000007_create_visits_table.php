<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per visit: what gets counted in reports. Branch, rank, age and
     * diagnosis live here because they describe that visit.
     */
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            // Generated from the id right after insert (V-000001).
            $table->string('visit_no', 12)->nullable()->unique();
            $table->foreignId('patient_id')->constrained('patients');
            $table->dateTime('visited_at');
            $table->unsignedTinyInteger('age'); // auto from birthdate, editable; reports use this value
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('rank_id')->constrained('ranks');
            $table->string('rank_other', 60)->nullable();
            $table->foreignId('diagnosis_id')->constrained('diagnoses');
            $table->string('diagnosis_other', 120)->nullable();
            $table->enum('category', ['OPD', 'ER', 'Admission']);
            $table->text('remarks')->nullable();
            $table->foreignId('encoded_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users');
            $table->string('delete_reason')->nullable();
            // Set when the visit went to Trash through "Delete month report".
            $table->foreignId('month_deletion_id')->nullable()->constrained('month_deletions')->nullOnDelete();

            $table->index(['category', 'visited_at', 'deleted_at']); // reports
            $table->index(['patient_id', 'visited_at']);             // repeat checks
            $table->index(['encoded_by', 'visited_at']);             // "My entries today"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
