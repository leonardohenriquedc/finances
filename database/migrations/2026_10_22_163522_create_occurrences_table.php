<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('description');
            $table->string('category');
            $table->string('direction');
            $table->string('kind');
            /** SQLite requires text affinity to retain exact decimal values. */
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->string('amount', 16);
            } else {
                $table->decimal('amount', 15, 2);
            }
            $table->date('competence_date');
            $table->date('due_date');
            $table->date('paid_at')->nullable();
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->string('paid_amount', 16)->nullable();
            } else {
                $table->decimal('paid_amount', 15, 2)->nullable();
            }
            $table->boolean('is_override')->default(false);
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();
            $table->unique(['financial_plan_id', 'sequence']);
            $table->index(['user_id', 'is_deleted', 'due_date']);
            $table->index(['user_id', 'is_deleted', 'competence_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('occurrences');
    }
};
