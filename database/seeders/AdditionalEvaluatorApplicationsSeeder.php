<?php

namespace Database\Seeders;

use App\Models\EvaluatorVerification;
use App\Models\User;
use App\Support\AdminAudit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

class AdditionalEvaluatorApplicationsSeeder extends Seeder
{
    private const APPROVAL_NOTE = 'Approved directly at the project owner\'s request.';

    private const REVIEW_NOTES = 'Seeded development application created by AdditionalEvaluatorApplicationsSeeder for the project owner\'s local development accounts. This is a simulated review of synthetic sample evidence, not independently verified professional credentials. The attestation and review checklist flags are development fixtures, not a real applicant declaration or an independent administrator investigation.';

    /** @var list<int> */
    private array $createdApplications = [];

    /** @var list<string> Only paths newly written by this invocation. */
    private array $createdFiles = [];

    /** @return list<int> */
    public function createdApplicationIds(): array
    {
        return $this->createdApplications;
    }

    /** Call after an enclosing transaction rolls back, if this seeder ran within it. */
    public function cleanupCreatedFiles(): void
    {
        $disk = Storage::disk('local');
        $failedPaths = [];
        $failure = null;

        foreach ($this->createdFiles as $path) {
            try {
                if ($disk->exists($path) && ! $disk->delete($path)) {
                    throw new RuntimeException('Could not remove newly created development application evidence.');
                }
            } catch (Throwable $exception) {
                $failedPaths[] = $path;
                $failure ??= $exception;
            }
        }

        $this->createdFiles = $failedPaths;
        $this->createdApplications = [];

        if ($failure) {
            throw new RuntimeException('Could not remove all newly created development application evidence.', 0, $failure);
        }
    }

    public function run(): void
    {
        $this->createdApplications = [];
        $this->createdFiles = [];

        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Additional evaluator applications may only be seeded in local or testing environments.');
        }

        if (config('filesystems.disks.local.driver') !== 'local') {
            throw new LogicException('Development application evidence requires the private local storage disk.');
        }

        try {
            $ids = DB::transaction(function () {
                $admin = User::where('role', 'admin')->where('is_suspended', false)->orderBy('id')->lockForUpdate()->first();
                if (! $admin || ! $admin->isAdmin()) {
                    throw new LogicException('An existing active administrator is required to seed development evaluator applications.');
                }

                $ids = [];

                foreach ($this->profiles() as $profile) {
                    $legacyEmail = str_replace('@analista.test', '@evaluators.analista.test', $profile['email']);
                    $legacy = User::where('email', $legacyEmail)->lockForUpdate()->first();
                    $user = User::where('email', $profile['email'])->lockForUpdate()->first();

                    if ($legacy && $user) {
                        throw new LogicException('Cannot seed an evaluator application: the legacy and current logins belong to separate accounts.');
                    }

                    if (! $user) {
                        continue;
                    }

                    if ($user->email !== $profile['email'] || $user->role !== 'evaluator') {
                        throw new LogicException('Cannot seed an evaluator application: the reserved login already belongs to another account.');
                    }

                    // A login alone never authorizes a new approval for an edited or moderated profile.
                    if (! $this->isUntouchedSeedProfile($user, $profile)
                        || $user->evaluatorVerifications()->exists()) {
                        continue;
                    }

                    $ids[] = $this->createApplication($user, $admin, $profile);
                }

                return $ids;
            });
        } catch (Throwable $exception) {
            try {
                $this->cleanupCreatedFiles();
            } catch (Throwable) {
                throw new RuntimeException('Development application seeding failed and its evidence cleanup was incomplete.', 0, $exception);
            }

            throw $exception;
        }

        $this->createdApplications = $ids;
    }

    private function isUntouchedSeedProfile(User $user, array $profile): bool
    {
        if (! $user->isEvaluator() || $user->moderation_note !== self::APPROVAL_NOTE) {
            return false;
        }

        foreach (['name', 'headline', 'bio', 'expertise', 'skills'] as $field) {
            if ($user->{$field} !== $profile[$field]) {
                return false;
            }
        }

        return $user->experience_years === 0
            && $user->review_modes === ['online']
            && $user->portfolio_url === null;
    }

    private function createApplication(User $user, User $admin, array $profile): int
    {
        $topics = implode(' and ', $profile['expertise']);
        $sampleReview = $this->sampleReview($profile['focus']);

        $verification = $user->evaluatorVerifications()->create([
            'profile_snapshot' => $user->evaluatorProfileSnapshot(),
            'profile_fingerprint' => $user->evaluatorProfileFingerprint(),
            'background_type' => 'freelance',
            'organization' => null,
            'background_summary' => 'This local development application models independent volunteer project review in '.$topics.'. It contains synthetic methodology and mock evaluation material for previewing the application workflow, with no employment or professional credential claims.',
            'evidence_type' => 'document',
            'evidence_url' => null,
            'evidence_explanation' => 'The attached development sample contains a synthetic attendance application design, review methodology, and sample feedback. It is a readable workflow fixture, not proof of identity, employment, or professional credentials.',
            'reference_name' => null,
            'reference_email' => null,
            'reference_relationship' => null,
            // Replaced with this application's protected evidence link before validation and commit.
            'work_sample_url' => route('account.evaluator-verification.show'),
            'work_sample_explanation' => 'The protected PDF contains an original synthetic work sample focused on '.$topics.', including mock design choices, objective-linked checks, and constructive feedback on the application\'s actual sample prompt. No real student work or professional history is represented.',
            'sample_review' => $sampleReview,
            'attestation' => true,
            'status' => 'approved',
            'reviewer_id' => $admin->id,
            'reviewed_at' => now(),
            'review_notes' => self::REVIEW_NOTES,
            'checks' => array_fill_keys(array_keys(EvaluatorVerification::REVIEW_CHECKS), true),
        ]);

        $disk = Storage::disk('local');
        $pdf = $this->developmentPdf($profile, $sampleReview);
        if ((new \finfo(FILEINFO_MIME_TYPE))->buffer($pdf) !== 'application/pdf') {
            throw new RuntimeException('Development application evidence must be a valid PDF document.');
        }

        do {
            $path = 'evaluator-evidence/'.$user->id.'/'.$verification->id.'/development-application-'.Str::random(40).'.pdf';
        } while ($disk->exists($path));

        // Track before writing so a partial or failed write can also be removed.
        $this->createdFiles[] = $path;
        if (! $disk->put($path, $pdf, ['visibility' => 'private']) || $disk->size($path) !== strlen($pdf)) {
            throw new RuntimeException('Could not write complete private development application evidence.');
        }

        $evidence = $verification->evidence()->create([
            'path' => $path,
            'original_name' => 'development-application.pdf',
            'mime_type' => 'application/pdf',
            'size' => strlen($pdf),
        ]);

        $verification->forceFill([
            'work_sample_url' => route('account.evaluator-evidence.download', ['evidence' => $evidence->id]),
        ])->save();

        if (! $verification->isComplete() || ! $verification->matchesProfile($user)) {
            throw new LogicException('Development evaluator applications must be complete and match the unchanged current profile.');
        }

        AdminAudit::record(
            actor: $admin,
            action: 'evaluator.approved',
            targetType: 'evaluator',
            targetId: (int) $user->id,
            before: ['evaluator_status' => $user->evaluator_status, 'verification_status' => null],
            after: ['evaluator_status' => $user->evaluator_status, 'verification_status' => 'approved'],
            reason: self::REVIEW_NOTES,
            metadata: ['verification_id' => $verification->id],
        );

        return (int) $verification->id;
    }

    private function sampleReview(string $focus): string
    {
        return 'Your attendance application has a useful goal, but the excerpt needs a stronger connection between its claims and its evidence. Five available classmates form a small convenience sample. Their opinions about usefulness do not demonstrate attendance accuracy or support a claim about every student and teacher. State this limitation, narrow the initial conclusion, define the intended users, and propose a justified recruitment plan with appropriate consent. Compare recorded attendance against a known reference dataset and report errors rather than assuming accuracy. '
            .'Please replace plain text password storage with the framework\'s password hashing facilities; administrators should support password resets instead of retrieving passwords. Define student, teacher, and administrator permissions, enforce them on the server, and test that users cannot read or edit another class\'s records. Use synthetic records while designing these checks. '
            .'Opening the home page only checks a small part of the system. Map each research objective to a measurable test, expected result, and acceptance criterion. Include incorrect sign-ins, unauthorized edits, duplicate attendance, recovery from failed requests, and usable feedback for errors. Record the method, limitations, and observed results before revising the claims. '.$focus.' These are practical recommendations for the next draft, offered as volunteer project feedback rather than a certification of the system.';
    }

    /** Builds a deterministic, readable PDF without an external generator or fabricated certificate. */
    private function developmentPdf(array $profile, string $sampleReview): string
    {
        $paragraphs = [
            'DEVELOPMENT SAMPLE',
            'Synthetic evaluator application and work sample',
            'This document is a local development fixture. It makes no employment or credential claims. It is not a government certificate, identity document, or independent professional verification. All designs, records, and proposed checks below are synthetic examples; no results are claimed.',
            'Example review topics: '.implode(', ', $profile['expertise']).'.',
            'MOCK WORK SAMPLE: ATTENDANCE APPLICATION',
            $profile['methodology'],
            'Method: write the objective, expected outcome, mock input, and acceptance criterion before each check. Use only synthetic accounts and attendance records. Record actual outcomes only if a real development test is later performed; this sample contains a plan, not measured results.',
            'APPLICATION SAMPLE PROMPT',
            EvaluatorVerification::SAMPLE_PROMPT,
            'CONSTRUCTIVE SAMPLE REVIEW',
            $sampleReview,
            'DEVELOPMENT REVIEW DISCLOSURE',
            self::REVIEW_NOTES,
        ];

        $lines = [];
        foreach ($paragraphs as $paragraph) {
            foreach (explode("\n", wordwrap(Str::ascii($paragraph), 85, "\n", true)) as $line) {
                $lines[] = trim($line);
            }
            $lines[] = '';
        }

        $pages = array_chunk($lines, 49);
        $pageReferences = [];
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>',
        ];

        foreach ($pages as $index => $page) {
            $pageId = 4 + $index * 2;
            $contentId = $pageId + 1;
            $pageReferences[] = $pageId.' 0 R';
            $stream = "BT\n/F1 9.5 Tf\n13 TL\n54 744 Td\n";
            $page = array_merge(['Development sample - page '.($index + 1).' of '.count($pages), ''], $page);

            foreach ($page as $line) {
                $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
                $stream .= '('.$escaped.") Tj\nT*\n";
            }
            $stream .= "ET\n";

            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents '.$contentId.' 0 R >>';
            $objects[$contentId] = '<< /Length '.strlen($stream).">>\nstream\n".$stream.'endstream';
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $pageReferences).'] /Count '.count($pages).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$body."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1).' /Root 1 0 R >>'."\nstartxref\n".$xref."\n%%EOF\n";
    }

    /** @return list<array<string, mixed>> */
    private function profiles(): array
    {
        return [
            [
                'email' => 'adrian.mendoza@analista.test',
                'name' => 'Adrian Mendoza',
                'headline' => 'Web and database project reviewer',
                'bio' => 'I volunteer to help student teams improve web applications, database design, and practical testing plans through constructive project feedback.',
                'expertise' => ['Web', 'Database'],
                'skills' => ['Laravel', 'PHP', 'MySQL'],
                'methodology' => 'Mock design for web and database review: separate users, classes, class memberships, and attendance entries. Use a unique key for the class, student, and session to prevent duplicate attendance. Restrict record changes to authorized class staff and validate inputs on the server. Plan checks for an allowed correction, a blocked cross-class edit, a duplicate submission, and rollback of a failed write. A synthetic reference table provides expected attendance values for an accuracy comparison.',
                'focus' => 'For the web and database design, document the attendance keys and constraints, then test a duplicate submission and a failed write to confirm that the records remain consistent.',
            ],
            [
                'email' => 'camille.villareal@analista.test',
                'name' => 'Camille Villareal',
                'headline' => 'Mobile and UI / UX project reviewer',
                'bio' => 'I volunteer to help student teams improve mobile applications, interface design, and usability through clear, constructive project feedback.',
                'expertise' => ['Mobile', 'UI / UX'],
                'skills' => ['Flutter', 'Figma', 'Usability testing'],
                'methodology' => 'Mock design for mobile and interface review: sketch a sign-in screen, a class attendance list, and a permitted correction form using invented records. Label controls clearly, show selected attendance states in text, and preserve input after validation errors. Plan tasks for locating a class, identifying an absent student, and correcting an allowed record. Define successful completion, error count, and clear recovery messages before any usability session. Include keyboard navigation, readable contrast, and a small screen layout check.',
                'focus' => 'For the mobile interface, define realistic attendance tasks, observe completion and errors, and check labels, keyboard access, contrast, and recovery messages rather than relying only on opinions about appearance.',
            ],
            [
                'email' => 'nathaniel.reyes@analista.test',
                'name' => 'Nathaniel Reyes',
                'headline' => 'Security and networking project reviewer',
                'bio' => 'I volunteer to help student teams review access controls, network design, and testing plans through practical, constructive project feedback.',
                'expertise' => ['Security', 'Networking'],
                'skills' => ['Access control', 'Network design', 'Test planning'],
                'methodology' => 'Mock design for security and networking review: define synthetic student, teacher, and administrator roles with a written permission matrix. Students may view only their own attendance; teachers may update only assigned classes. Plan server-side denial checks for anonymous requests, changed record identifiers, and cross-class edits. Use password hashes and a reset flow, restrict database access to the application, and protect requests in transit. Include a lost-connection scenario that reports failure clearly without silently duplicating attendance on retry.',
                'focus' => 'For security and networking, turn the role matrix into allowed and denied request checks, verify cross-class isolation, and plan safe retries when a connection fails during an attendance update.',
            ],
        ];
    }
}
