<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class AdditionalEvaluatorSeeder extends Seeder
{
    private const LEGACY_BIO = 'Sample evaluator profile for local development. This account previews the evaluator directory, messages, and review workflow. Its listed topics and skills are examples; no professional credentials or supporting evidence have been verified.';

    private const LEGACY_MODERATION_NOTE = 'Auto-approved sample account created at the project owner\'s request. No verification evidence was submitted or reviewed.';

    /** @var list<array{name: string, email: string, password: string}> */
    private array $createdCredentials = [];

    /** @return list<array{name: string, email: string, password: string}> */
    public function credentials(): array
    {
        return $this->createdCredentials;
    }

    public function run(): void
    {
        $this->createdCredentials = [];

        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Additional evaluator accounts may only be seeded in local or testing environments.');
        }

        if (! User::where('role', 'admin')->where('is_suspended', false)->exists()) {
            throw new LogicException('Create an active administrator before seeding approved evaluator applications.');
        }

        $profiles = [
            [
                'email' => 'adrian.mendoza@analista.test',
                'name' => 'Adrian Mendoza',
                'headline' => 'Web and database project reviewer',
                'bio' => 'I volunteer to help student teams improve web applications, database design, and practical testing plans through constructive project feedback.',
                'expertise' => ['Web', 'Database'],
                'skills' => ['Laravel', 'PHP', 'MySQL'],
            ],
            [
                'email' => 'camille.villareal@analista.test',
                'name' => 'Camille Villareal',
                'headline' => 'Mobile and UI / UX project reviewer',
                'bio' => 'I volunteer to help student teams improve mobile applications, interface design, and usability through clear, constructive project feedback.',
                'expertise' => ['Mobile', 'UI / UX'],
                'skills' => ['Flutter', 'Figma', 'Usability testing'],
            ],
            [
                'email' => 'nathaniel.reyes@analista.test',
                'name' => 'Nathaniel Reyes',
                'headline' => 'Security and networking project reviewer',
                'bio' => 'I volunteer to help student teams review access controls, network design, and testing plans through practical, constructive project feedback.',
                'expertise' => ['Security', 'Networking'],
                'skills' => ['Access control', 'Network design', 'Test planning'],
            ],
        ];

        $applications = new AdditionalEvaluatorApplicationsSeeder;
        try {
            $credentials = DB::transaction(function () use ($profiles, $applications) {
                $credentials = [];

                foreach ($profiles as $profile) {
                    $legacyEmail = str_replace('@analista.test', '@evaluators.analista.test', $profile['email']);
                    $legacy = User::where('email', $legacyEmail)->lockForUpdate()->first();
                    $current = User::where('email', $profile['email'])->lockForUpdate()->first();

                    if ($legacy && $current) {
                        throw new LogicException('Cannot convert '.$legacyEmail.': the destination login already belongs to another account.');
                    }

                    // Existing regular accounts keep their credentials and moderation decisions.
                    if ($current) {
                        if ($current->role !== 'evaluator') {
                            throw new LogicException('Cannot create evaluator: '.$profile['email'].' already belongs to another account.');
                        }

                        continue;
                    }

                    // Only the exact legacy seed markers authorize conversion of an existing row.
                    if ($legacy && ($legacy->role !== 'evaluator'
                        || $legacy->bio !== self::LEGACY_BIO
                        || $legacy->moderation_note !== self::LEGACY_MODERATION_NOTE)) {
                        throw new LogicException('Cannot convert '.$legacyEmail.': this is no longer an untouched legacy evaluator seed.');
                    }

                    do {
                        $password = Str::random(24);
                    } while (in_array($password, array_column($credentials, 'password'), true));

                    $attributes = $profile + [
                        'password' => $password,
                        'email_verified_at' => null,
                        'remember_token' => Str::random(60),
                        'role' => 'evaluator',
                        'evaluator_status' => 'approved',
                        'is_suspended' => false,
                        'accepting_requests' => true,
                        'capacity' => 3,
                        'review_modes' => ['online'],
                        'experience_years' => 0,
                        'moderation_note' => 'Approved directly at the project owner\'s request.',
                    ];

                    if ($legacy) {
                        $legacy->update($attributes);
                    } else {
                        User::create($attributes);
                    }

                    $credentials[] = ['name' => $profile['name'], 'email' => $profile['email'], 'password' => $password];
                }

                $applications->run();

                return $credentials;
            });
        } catch (Throwable $exception) {
            $applications->cleanupCreatedFiles();
            throw $exception;
        }

        // Keep plaintext only in memory, after the entire transaction succeeds.
        $this->createdCredentials = $credentials;

        if ($this->command && $credentials !== []) {
            $this->command->info('Evaluator logins created or converted. Copy these passwords now; they are not stored in plaintext.');
            $this->command->table(['Name', 'Login email', 'Password'], array_map(array_values(...), $credentials));
        }
    }
}
