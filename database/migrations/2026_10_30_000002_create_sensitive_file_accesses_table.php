<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensitive_file_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 16);
            $table->string('file_type', 32);
            $table->unsignedBigInteger('file_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('verification_id')->nullable();
            $table->unsignedInteger('version')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['created_at', 'id']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['file_type', 'created_at']);
            $table->index(['file_type', 'file_id']);
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensitive_file_accesses');
    }
};
