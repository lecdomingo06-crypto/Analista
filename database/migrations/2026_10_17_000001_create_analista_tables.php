<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('student')->index();
            $table->boolean('is_suspended')->default(false);
            $table->string('headline')->nullable();
            $table->string('city')->nullable();
            $table->text('bio')->nullable();
            $table->json('expertise')->nullable();
            $table->json('skills')->nullable();
            $table->unsignedInteger('experience_years')->default(0);
            $table->unsignedInteger('capacity')->default(3);
            $table->boolean('accepting_requests')->default(true);
            $table->json('review_modes')->nullable();
            $table->string('portfolio_url', 2048)->nullable();
            $table->string('evaluator_status')->default('none')->index();
            $table->text('moderation_note')->nullable();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('summary');
            $table->string('school')->nullable();
            $table->string('course')->nullable();
            $table->string('category')->index();
            $table->json('technologies')->nullable();
            $table->text('feedback_needed');
            $table->string('status')->default('pending')->index();
            $table->text('moderation_note')->nullable();
            $table->date('deadline')->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamps();
            $table->index(['status', 'is_public']);
        });

        Schema::create('project_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('path', 1024);
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('version');
            $table->timestamps();
            $table->unique(['project_id', 'version']);
        });

        Schema::create('review_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evaluator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('pending')->index();
            $table->text('message');
            $table->date('preferred_date')->nullable();
            $table->date('agreed_date')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['evaluator_id', 'status']);
        });

        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('section')->nullable();
            $table->unsignedInteger('page')->nullable();
            $table->string('type')->default('recommendation');
            $table->text('body');
            $table->string('priority')->default('suggestion');
            $table->boolean('addressed')->default(false);
            $table->timestamps();
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reportable_type');
            $table->unsignedBigInteger('reportable_id');
            $table->string('reason');
            $table->text('details')->nullable();
            $table->string('status')->default('pending')->index();
            $table->text('admin_note')->nullable();
            $table->string('action')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['reportable_type', 'reportable_id']);
        });

        Schema::create('tutorials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('category')->index();
            $table->text('excerpt');
            $table->longText('body');
            $table->unsignedInteger('reading_minutes')->default(5);
            $table->boolean('is_published')->default(true)->index();
            $table->boolean('featured')->default(false);
            $table->timestamps();
        });

        Schema::create('tutorial_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutorial_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['tutorial_id', 'user_id']);
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url', 2048);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evaluator_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['review_request_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['ratings', 'invitations', 'activities', 'tutorial_user', 'tutorials', 'reports', 'feedback', 'review_requests', 'document_versions', 'project_user', 'projects'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropIndex(['evaluator_status']);
            $table->dropColumn(['role', 'is_suspended', 'headline', 'city', 'bio', 'expertise', 'skills', 'experience_years', 'capacity', 'accepting_requests', 'review_modes', 'portfolio_url', 'evaluator_status', 'moderation_note']);
        });
    }
};
