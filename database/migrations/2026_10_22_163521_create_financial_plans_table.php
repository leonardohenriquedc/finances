<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->string('category');
            $table->string('direction');
            $table->string('kind');
            /** SQLite NUMERIC affinity converts decimal strings to binary floats. */
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->string('amount', 16);
            } else {
                $table->decimal('amount', 15, 2);
            }
            $table->string('amount_mode')->default('total');
            $table->unsignedSmallInteger('installment_count')->default(1);
            $table->string('frequency')->default('monthly');
            $table->string('interest_type')->default('none');
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->string('interest_rate', 9)->default('0.0000');
            } else {
                $table->decimal('interest_rate', 8, 4)->default(0);
            }
            $table->string('interest_period')->default('monthly');
            $table->date('competence_date');
            $table->date('first_due_date');
            $table->date('end_date')->nullable();
            $table->unsignedInteger('occurrence_limit')->nullable();
            $table->string('state')->default('active');
            $table->date('projection_resume_date')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'state']);
            $table->index(['state', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_plans');
    }
};
