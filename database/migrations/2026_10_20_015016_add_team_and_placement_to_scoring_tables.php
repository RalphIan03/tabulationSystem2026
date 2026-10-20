<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('participants', function (Blueprint $table): void {
            $table->foreignId('parent_participant_id')->nullable()->constrained('participants')->nullOnDelete();
        });

        Schema::table('competition_results', function (Blueprint $table): void {
            $table->unsignedInteger('placement')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('competition_results', function (Blueprint $table): void {
            $table->dropColumn('placement');
        });

        Schema::table('participants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_participant_id');
        });
    }
};
