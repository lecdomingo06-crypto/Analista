<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new LogicException('Sample accounts are isolated test fixtures only. Invite real professionals or ask them to apply; an admin must approve them.');
        }

        DB::transaction(function () {
            $this->call(TutorialSeeder::class);

            $password = Hash::make('AnalistaDemo!2026');
            $admin = $this->user('admin@analista.test', 'Community Admin', $password, ['role' => 'admin']);
            $student = $this->user('student@analista.test', 'Alex Rivera', $password, ['role' => 'student']);
            $member = $this->user('lia@analista.test', 'Lia Mendoza', $password, ['role' => 'student']);

            $reviewers = [
                ['email' => 'evaluator@analista.test', 'name' => 'Sofia Reyes', 'headline' => 'Full-stack developer · volunteer reviewer', 'city' => 'Quezon City', 'experience_years' => 6, 'expertise' => ['Web', 'Database', 'UI / UX'], 'skills' => ['Laravel', 'React', 'MySQL', 'System architecture'], 'bio' => 'Sample profile for exploring Analista. Sofia helps student groups explain design decisions, improve database relationships, and connect system requirements to a practical evaluation plan.'],
                ['email' => 'miguel@analista.test', 'name' => 'Miguel Santos', 'headline' => 'Mobile engineer · building useful experiences', 'city' => 'Cebu City', 'experience_years' => 5, 'expertise' => ['Mobile', 'UI / UX'], 'skills' => ['Flutter', 'Dart', 'Accessibility', 'Usability testing'], 'bio' => 'Sample profile for exploring Analista. Miguel focuses on mobile user flows, accessible interfaces, and test scenarios that reflect real tasks.'],
                ['email' => 'danica@analista.test', 'name' => 'Danica Cruz', 'headline' => 'Data engineer · making research measurable', 'city' => 'Davao City', 'experience_years' => 7, 'expertise' => ['AI / ML', 'Database'], 'skills' => ['Python', 'SQL', 'Data preparation', 'Model evaluation'], 'bio' => 'Sample profile for exploring Analista. Danica reviews data preparation, evaluation methods, and clear reporting of limitations in data-driven capstone projects.'],
                ['email' => 'rafael@analista.test', 'name' => 'Rafael Navarro', 'headline' => 'Security analyst · thoughtful systems review', 'city' => 'Iloilo City', 'experience_years' => 8, 'expertise' => ['Security', 'Networking', 'Web'], 'skills' => ['Access control', 'Threat modeling', 'API security', 'Network design'], 'bio' => 'Sample profile for exploring Analista. Rafael helps teams identify trust boundaries, explain permissions, and document security assumptions in their proposed systems.'],
                ['email' => 'bea@analista.test', 'name' => 'Bea Flores', 'headline' => 'UX researcher · helping teams ask better questions', 'city' => 'Baguio City', 'experience_years' => 4, 'expertise' => ['UI / UX', 'Education', 'Web'], 'skills' => ['User research', 'Figma', 'Interview guides', 'Usability studies'], 'bio' => 'Sample profile for exploring Analista. Bea reviews research questions, user journeys, and study materials, with an emphasis on evidence and understandable interfaces.'],
                ['email' => 'nico@analista.test', 'name' => 'Nico Villanueva', 'headline' => 'IoT developer · from prototype to practical testing', 'city' => 'Bacolod City', 'experience_years' => 5, 'expertise' => ['IoT / Hardware', 'Mobile', 'Networking'], 'skills' => ['Arduino', 'ESP32', 'MQTT', 'Prototype testing'], 'bio' => 'Sample profile for exploring Analista. Nico supports student teams with prototype boundaries, hardware test plans, and clear documentation of device limitations.'],
            ];

            foreach ($reviewers as $reviewer) {
                $email = $reviewer['email'];
                $name = $reviewer['name'];
                unset($reviewer['email'], $reviewer['name']);
                $this->user($email, $name, $password, $reviewer + [
                    'role' => 'evaluator',
                    'evaluator_status' => 'approved',
                    'accepting_requests' => true,
                    'capacity' => 3,
                    'review_modes' => ['online'],
                ]);
            }

            $this->user('pending@analista.test', 'Jamie Torres', $password, [
                'role' => 'evaluator',
                'evaluator_status' => 'pending',
                'headline' => 'QA engineer · evaluator application pending',
                'city' => 'Pasig City',
                'experience_years' => 3,
                'expertise' => ['Web', 'Mobile'],
                'skills' => ['Test planning', 'API testing'],
                'review_modes' => ['online'],
                'bio' => 'Sample evaluator application awaiting an administrator decision. Interested in helping student groups prepare meaningful test cases.',
            ]);

            $projects = [
                ['title' => 'Bayanihan: a community volunteer coordination platform', 'category' => 'Web', 'technologies' => ['Laravel', 'MySQL', 'Tailwind CSS'], 'summary' => 'A sample thesis project exploring how local organizations can coordinate volunteer opportunities, availability, and participation records in one place. This demonstration listing contains no uploaded thesis document.', 'feedback_needed' => 'We would appreciate recommendations on our database relationships, volunteer matching flow, and the usability tasks proposed in Chapter 3.'],
                ['title' => 'StudySpace: finding a place to focus on campus', 'category' => 'Mobile', 'technologies' => ['Flutter', 'Firebase', 'Figma'], 'summary' => 'A sample capstone concept for helping students discover available study spaces and understand the facilities offered in each location. This demonstration listing contains no uploaded thesis document.', 'feedback_needed' => 'Please review the scope of our mobile prototype and suggest improvements to our accessibility and usability evaluation plan.'],
                ['title' => 'HarvestLink: connecting growers with community buyers', 'category' => 'Database', 'technologies' => ['PHP', 'MySQL', 'JavaScript'], 'summary' => 'A sample project investigating a simple produce availability and order coordination system for a small community. This demonstration listing contains no uploaded thesis document.', 'feedback_needed' => 'We need feedback on order state transitions, inventory consistency, and whether our specific objectives are measurable.'],
                ['title' => 'AquaSense: monitoring water use through a small IoT prototype', 'category' => 'IoT / Hardware', 'technologies' => ['ESP32', 'MQTT', 'Python'], 'summary' => 'A sample thesis concept studying how a sensor prototype can summarize water consumption and communicate device limitations to users. This demonstration listing contains no uploaded thesis document.', 'feedback_needed' => 'We are looking for recommendations on test conditions, sensor calibration documentation, and the boundaries of our findings.'],
                ['title' => 'ClearPath: an accessible student service request portal', 'category' => 'UI / UX', 'technologies' => ['React', 'Laravel', 'PostgreSQL'], 'summary' => 'A sample student project proposing a clearer way to submit campus service requests and follow their progress through accessible interfaces. This demonstration listing contains no uploaded thesis document.', 'feedback_needed' => 'Please check our user journeys and research instruments, particularly how we plan to observe users completing core tasks.'],
                ['title' => 'SafeArchive: managing access to a small digital records collection', 'category' => 'Security', 'technologies' => ['Laravel', 'SQLite', 'Docker'], 'summary' => 'A sample capstone focused on role-based access, document version tracking, and understandable audit records for a small organization. This demonstration listing contains no uploaded thesis document.', 'feedback_needed' => 'We would like a review of our permission model, threat assumptions, and proposed functional test coverage.'],
            ];

            foreach ($projects as $index => $attributes) {
                $owner = $index % 2 === 0 ? $student : $member;
                $project = Project::firstOrCreate(['title' => $attributes['title'], 'user_id' => $owner->id], $attributes + [
                    'user_id' => $owner->id,
                    'school' => 'Sample Community College',
                    'course' => 'BS Information Technology',
                    'status' => 'approved',
                    'is_public' => true,
                    'deadline' => now()->addDays(14 + $index * 3)->toDateString(),
                ]);
                $project->members()->syncWithoutDetaching([$student->id, $member->id]);
            }

            Project::firstOrCreate(['title' => 'CampusQueue: student service appointment prototype', 'user_id' => $student->id], [
                'user_id' => $student->id,
                'summary' => 'A sample submission awaiting admin moderation. The team proposes an appointment request prototype for a campus service desk. No thesis document has been uploaded to this demonstration project.',
                'school' => 'Sample Community College',
                'course' => 'BS Information Technology',
                'category' => 'Web',
                'technologies' => ['Laravel', 'MySQL'],
                'feedback_needed' => 'Please review whether our objectives match the problem statement and identify missing details before we begin implementation.',
                'status' => 'pending',
                'is_public' => false,
                'deadline' => now()->addWeeks(4)->toDateString(),
            ]);

            if (! $admin->activities()->where('title', 'Sample applications are ready to review')->exists()) {
                $admin->notifyActivity('Sample applications are ready to review', 'Demo data: one evaluator application and one thesis project are awaiting your decision.', '/admin');
            }

            if (! $student->activities()->where('title', 'Welcome to the Analista demo')->exists()) {
                $student->notifyActivity('Welcome to the Analista demo', 'These fictional sample projects have no document uploads yet. Create a project and upload your own PDF to try the complete review process.', '/account');
            }
        });
    }

    private function user(string $email, string $name, string $password, array $attributes): User
    {
        return User::firstOrCreate(['email' => $email], $attributes + [
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'email_verified_at' => now(),
            'role' => 'student',
            'is_suspended' => false,
            'evaluator_status' => 'none',
        ]);
    }
}
