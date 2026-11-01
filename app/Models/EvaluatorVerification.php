<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class EvaluatorVerification extends Model
{
    public const SAMPLE_PROMPT = <<<'TEXT'
This is a synthetic thesis excerpt for a sample review, not a real student's work:

“Our proposed attendance application will improve attendance accuracy across the entire school. We surveyed five classmates who were available during lunch, and all five said the application would be useful. These responses prove the application is accurate and suitable for every student and teacher. The system stores account passwords as plain text so administrators can retrieve them easily. Everyone who signs in can view and edit all attendance records; no separate access controls are needed. Testing will consist of opening the home page and asking whether it looks good. We have not defined tests linked to the research objectives.”

Provide constructive recommendations for this excerpt. Identify concerns about the evidence, research claims, security, and evaluation plan. Explain why they matter and suggest specific next steps. Keep your feedback respectful and within the role of a volunteer thesis reviewer.
TEXT;

    public const REVIEW_CHECKS = [
        'identity' => 'Identity and professional background checked',
        'expertise' => 'Expertise and work sample checked',
        'evidence' => 'Supporting evidence authenticity checked',
        'feedback' => 'Sample feedback quality checked',
    ];

    public const BACKGROUND_TYPES = [
        'employed' => 'Employed professional',
        'freelance' => 'Freelance or independent professional',
        'academic' => 'Academic or research professional',
    ];

    public const EVIDENCE_TYPES = [
        'document' => 'Supporting document',
        'portfolio' => 'Portfolio or professional profile',
        'reference' => 'Professional reference',
    ];

    protected $guarded = [];

    protected $hidden = [
        'profile_snapshot', 'profile_fingerprint', 'background_type', 'organization', 'background_summary',
        'evidence_type', 'evidence_url', 'evidence_explanation', 'reference_name', 'reference_email',
        'reference_relationship', 'work_sample_url', 'work_sample_explanation', 'sample_review',
        'attestation', 'review_notes', 'checks',
    ];

    protected function casts(): array
    {
        return [
            'profile_snapshot' => 'array',
            'attestation' => 'boolean',
            'reviewed_at' => 'datetime',
            'checks' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(EvaluatorEvidence::class);
    }

    public function matchesProfile(User $user): bool
    {
        return (int) $this->user_id === (int) $user->id
            && is_string($this->profile_fingerprint)
            && strlen($this->profile_fingerprint) === 64
            && hash_equals($this->profile_fingerprint, $user->evaluatorProfileFingerprint());
    }

    public function isComplete(): bool
    {
        $profile = $this->profile_snapshot;
        if (! is_array($profile)
            || ! $this->hasText($profile['name'] ?? null)
            || ! $this->hasText($profile['headline'] ?? null)
            || ! $this->hasText($profile['bio'] ?? null, 30)
            || ! $this->hasStringList($profile['expertise'] ?? null, 1, 6)
            || ! $this->hasStringList($profile['review_modes'] ?? null, 1, 2)
            || array_diff($profile['review_modes'], ['online', 'on-site']) !== []
            || ! array_key_exists($this->background_type ?? '', self::BACKGROUND_TYPES)
            || ! $this->hasText($this->background_summary, 80)
            || ! $this->hasHttpUrl($this->work_sample_url)
            || ! $this->hasText($this->work_sample_explanation, 50)
            || ! $this->hasText($this->sample_review, 150)
            || ! $this->attestation) {
            return false;
        }

        return match ($this->evidence_type) {
            'document' => $this->hasCompleteDocuments(),
            'portfolio' => $this->hasHttpUrl($this->evidence_url)
                && $this->hasText($this->evidence_explanation, 50),
            'reference' => $this->hasText($this->reference_name)
                && is_string($this->reference_email)
                && filter_var($this->reference_email, FILTER_VALIDATE_EMAIL) !== false
                && $this->hasText($this->reference_relationship)
                && $this->hasText($this->evidence_explanation, 50),
            default => false,
        };
    }

    private function hasCompleteDocuments(): bool
    {
        $documents = $this->evidence()->get();
        if ($documents->count() < 1 || $documents->count() > 3) {
            return false;
        }

        return $documents->every(fn (EvaluatorEvidence $document) => $this->hasText($document->path)
            && $this->hasText($document->original_name)
            && in_array($document->mime_type, ['application/pdf', 'image/jpeg', 'image/png'], true)
            && $document->size > 0 && $document->size <= 5 * 1024 * 1024
            && Storage::disk('local')->exists($document->path));
    }

    private function hasText(mixed $value, int $minimum = 1): bool
    {
        return is_string($value) && mb_strlen(trim($value)) >= $minimum;
    }

    private function hasHttpUrl(mixed $value): bool
    {
        return is_string($value) && mb_strlen($value) <= 500
            && filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function hasStringList(mixed $value, int $minimum, int $maximum): bool
    {
        return is_array($value) && array_is_list($value) && count($value) >= $minimum
            && count($value) <= $maximum
            && count(array_filter($value, fn ($item) => $this->hasText($item))) === count($value)
            && count(array_unique($value)) === count($value);
    }
}
