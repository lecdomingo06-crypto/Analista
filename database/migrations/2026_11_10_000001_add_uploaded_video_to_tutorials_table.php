<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutorials', function (Blueprint $table) {
            $table->string('video_path')->nullable();
            $table->string('video_original_name')->nullable();
            $table->string('video_mime_type', 40)->nullable();
            $table->unsignedBigInteger('video_size')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tutorials', fn (Blueprint $table) => $table->dropColumn(['video_path', 'video_original_name', 'video_mime_type', 'video_size']));
    }
};
