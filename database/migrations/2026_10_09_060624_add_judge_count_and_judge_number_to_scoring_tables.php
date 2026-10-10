<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $table): void {
            $table->unsignedTinyInteger('judge_count')->default(1)->after('scoring_method');
        });

        Schema::table('criterion_scores', function (Blueprint $table): void {
            $table->unsignedTinyInteger('judge_number')->default(1)->after('criterion_id');
            $table->dropUnique(['competition_result_id', 'criterion_id']);
            $table->unique(['competition_result_id', 'criterion_id', 'judge_number'], 'criterion_scores_result_criterion_judge_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('criterion_scores')->where('judge_number', '>', 1)->exists()) {
            throw new RuntimeException('Judge scores must be removed before this migration can be rolled back.');
        }

        Schema::table('criterion_scores', function (Blueprint $table): void {
            $table->dropUnique('criterion_scores_result_criterion_judge_unique');
            $table->unique(['competition_result_id', 'criterion_id']);
            $table->dropColumn('judge_number');
        });

        Schema::table('competitions', function (Blueprint $table): void {
            $table->dropColumn('judge_count');
        });
    }
};
