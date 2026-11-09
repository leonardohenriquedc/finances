<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_plans', function (Blueprint $table): void {
            $table->date('value_effective_after')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('financial_plans', function (Blueprint $table): void {
            $table->dropColumn('value_effective_after');
        });
    }
};
