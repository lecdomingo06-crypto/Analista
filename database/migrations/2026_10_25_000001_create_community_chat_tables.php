<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_key', 80)->index();
            $table->string('author_name');
            $table->boolean('is_guest');
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('community_chat_presences', function (Blueprint $table) {
            $table->id();
            $table->string('viewer_key', 80)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('guest_name')->nullable();
            $table->timestamp('last_seen_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_chat_presences');
        Schema::dropIfExists('community_chat_messages');
    }
};
