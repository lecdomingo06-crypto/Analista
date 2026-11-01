<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('direct_message_id')->constrained()->cascadeOnDelete();
            $table->string('name', 180);
            $table->string('path')->unique();
            $table->unsignedBigInteger('size');
            $table->string('mime_type', 127);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_message_attachments');
    }
};
