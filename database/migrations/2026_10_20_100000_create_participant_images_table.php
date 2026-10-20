<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participant_images', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('mime_type', 32);
            $table->longText('contents');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participant_images');
    }
};
