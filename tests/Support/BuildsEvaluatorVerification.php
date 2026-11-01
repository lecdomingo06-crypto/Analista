<?php

namespace Tests\Support;

use App\Models\EvaluatorVerification;
use App\Models\User;

trait BuildsEvaluatorVerification
{
    protected function professionalProfileData(User $user, array $overrides = []): array
    {
        return array_replace([
            'name' => $user->name, 'headline' => $user->headline, 'bio' => $user->bio,
            'city' => $user->city, 'expertise' => $user->expertise,
            'skills' => implode(', ', $user->skills ?? []), 'experience_years' => $user->experience_years,
            'capacity' => $user->capacity, 'review_modes' => $user->review_modes,
            'portfolio_url' => $user->portfolio_url,
        ], $overrides);
    }

    protected function verificationData(array $overrides = []): array
    {
        return array_replace([
            'background_type' => 'employed',
            'organization' => 'Independent application test organization',
            'background_summary' => 'I build and maintain database-backed applications, document technical decisions, and help junior developers review system design and practical testing approaches.',
            'evidence_type' => 'portfolio',
            'evidence_url' => 'https://example.org/private-verification/portfolio',
            'evidence_explanation' => 'This portfolio describes the application architecture and database design work that I personally completed on professional projects.',
            'work_sample_url' => 'https://example.org/private-verification/work-sample',
            'work_sample_explanation' => 'I designed the data model, documented the main application workflows, and wrote the integration tests for this sample project.',
            'sample_review' => 'The stated objective is promising, but the methodology needs a clearer connection to the research questions. Identify your participants and selection criteria, explain how each evaluation task measures an objective, and define how you will interpret the findings. Keep claims proportional to the evidence you collect.',
            'attestation' => true,
        ], $overrides);
    }

    protected function createVerification(User $user, array $overrides = []): EvaluatorVerification
    {
        return EvaluatorVerification::create(array_replace($this->verificationData(), [
            'user_id' => $user->id,
            'profile_snapshot' => $user->evaluatorProfileSnapshot(),
            'profile_fingerprint' => $user->evaluatorProfileFingerprint(),
            'status' => 'pending',
        ], $overrides));
    }

    protected function approvalData(EvaluatorVerification $verification, array $overrides = []): array
    {
        return array_replace([
            'verification_id' => $verification->id,
            'decision' => 'approve',
            'reason' => 'Reviewed the applicant background, linked evidence, work sample, and constructive review response against the current professional profile.',
            'checks' => ['identity' => true, 'expertise' => true, 'evidence' => true, 'feedback' => true],
        ], $overrides);
    }
}
