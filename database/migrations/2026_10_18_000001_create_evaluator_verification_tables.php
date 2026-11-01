<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluator_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('profile_snapshot');
            $table->char('profile_fingerprint', 64);
            $table->string('background_type');
            $table->string('organization')->nullable();
            $table->text('background_summary');
            $table->string('evidence_type');
            $table->string('evidence_url', 500)->nullable();
            $table->text('evidence_explanation')->nullable();
            $table->string('reference_name')->nullable();
            $table->string('reference_email')->nullable();
            $table->string('reference_relationship', 300)->nullable();
            $table->string('work_sample_url', 500);
            $table->text('work_sample_explanation');
            $table->text('sample_review');
            $table->boolean('attestation')->default(false);
            $table->string('status')->default('pending')->index();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->json('checks')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('evaluator_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluator_verification_id')->constrained()->cascadeOnDelete();
            $table->string('path', 1024);
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluator_evidence');
        Schema::dropIfExists('evaluator_verifications');
    }
};
