<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_plans', function (Blueprint $table): void {
            $table->string('priority', 20)->nullable();
        });
        Schema::table('occurrences', function (Blueprint $table): void {
            $table->string('priority', 20)->nullable();
            $table->index(['user_id', 'priority', 'due_date']);
        });

        DB::table('financial_plans')->where('direction', 'debit')->update(['priority' => 'when_possible']);
        DB::table('occurrences')->where('direction', 'debit')->update(['priority' => 'when_possible']);
    }

    public function down(): void
    {
        Schema::table('occurrences', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'priority', 'due_date']);
            $table->dropColumn('priority');
        });
        Schema::table('financial_plans', function (Blueprint $table): void {
            $table->dropColumn('priority');
        });
    }
};
