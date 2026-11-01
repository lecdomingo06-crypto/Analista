<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('direct_messages', function (Blueprint $table) {
            $table->string('kind', 30)->default('text');
            $table->foreignId('review_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('review_event', 30)->nullable();
            $table->string('review_event_key', 150)->nullable()->unique();
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->string('review_event_key', 150)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropUnique(['review_event_key']);
            $table->dropColumn('review_event_key');
        });
        Schema::table('direct_messages', function (Blueprint $table) {
            $table->dropForeign(['review_request_id']);
            $table->dropUnique(['review_event_key']);
            $table->dropColumn(['kind', 'review_request_id', 'review_event', 'review_event_key']);
        });
    }
};
