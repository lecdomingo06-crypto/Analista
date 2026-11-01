<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_requests', function (Blueprint $table) {
            $table->text('final_summary')->nullable();
            $table->string('output_path', 1024)->nullable();
            $table->string('output_original_name')->nullable();
            $table->unsignedBigInteger('output_size')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('review_requests', function (Blueprint $table) {
            $table->dropColumn(['final_summary', 'output_path', 'output_original_name', 'output_size']);
        });
    }
};
