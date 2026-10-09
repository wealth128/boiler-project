<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per person. Name, sex and birthdate live here, so correcting
     * them fixes all of that patient's visits.
     */
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            // Generated from the id right after insert (P-000001), so it is
            // briefly empty inside the saving transaction. Unique allows that.
            $table->string('patient_no', 12)->nullable()->unique();
            $table->string('last_name', 60);
            $table->string('first_name', 60);
            $table->string('middle_initial', 2)->nullable();
            $table->enum('sex', ['Male', 'Female']);
            $table->date('birthdate');
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users');
            $table->string('delete_reason')->nullable();

            // Returning-patient search by name.
            $table->index(['last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
