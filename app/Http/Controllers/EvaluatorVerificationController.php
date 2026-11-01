<?php

namespace App\Http\Controllers;

use App\Models\EvaluatorEvidence;
use App\Models\User;
use App\Support\SensitiveFileAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class EvaluatorVerificationController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->role === 'evaluator', 403);
        $verification = $user->evaluatorVerifications()->with(['evidence', 'reviewer'])->latest('id')->first();
        $history = $user->evaluatorVerifications()->with(['evidence', 'reviewer'])->latest('id')->paginate(10);
        $profileComplete = $this->profileComplete($user);
        $missingProfileFields = $this->missingProfileFields($user);
        $expertiseOptions = AccountController::EXPERTISE_OPTIONS;
        $profileCurrent = $verification?->matchesProfile($user) ?? false;
        $canSubmit = ! ($verification?->status === 'pending' && $profileCurrent);

        return view('account.verification', compact('user', 'verification', 'history', 'profileComplete', 'profileCurrent', 'canSubmit', 'missingProfileFields', 'expertiseOptions'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'evaluator' && ! $request->user()->is_suspended, 403);
        foreach ([
            'organization', 'background_summary', 'evidence_explanation', 'reference_name',
            'reference_relationship', 'work_sample_explanation', 'sample_review',
        ] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => trim(strip_tags($request->input($field)))]);
            }
        }
        $this->sanitizeProfile($request);
        $profileRequired = ! $this->profileComplete($request->user()) || $request->has('profile');
        $data = $request->validate([
            ...$this->profileRules($profileRequired),
            'background_type' => ['required', Rule::in(['employed', 'freelance', 'academic'])],
            'organization' => ['nullable', 'string', 'max:180'],
            'background_summary' => ['required', 'string', 'min:80', 'max:3000'],
            'evidence_type' => ['required', Rule::in(['document', 'portfolio', 'reference'])],
            'evidence_files' => ['required_if:evidence_type,document', 'prohibited_unless:evidence_type,document', 'array', 'min:1', 'max:3'],
            'evidence_files.*' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:5120'],
            'evidence_url' => ['required_if:evidence_type,portfolio', 'nullable', 'url:http,https', 'max:500'],
            'evidence_explanation' => ['required_if:evidence_type,portfolio,reference', 'nullable', 'string', 'min:50', 'max:3000'],
            'reference_name' => ['required_if:evidence_type,reference', 'nullable', 'string', 'max:120'],
            'reference_email' => ['required_if:evidence_type,reference', 'nullable', 'email', 'max:255'],
            'reference_relationship' => ['required_if:evidence_type,reference', 'nullable', 'string', 'max:300'],
            'work_sample_url' => ['required', 'url:http,https', 'max:500'],
            'work_sample_explanation' => ['required', 'string', 'min:50', 'max:3000'],
            'sample_review' => ['required', 'string', 'min:150', 'max:6000'],
            'attestation' => ['required', 'accepted'],
        ], [
            'evidence_files.required_if' => 'Upload at least one supporting document, or choose another evidence option.',
            'evidence_files.*.max' => 'Each evidence file must be 5 MB or smaller.',
            'attestation.accepted' => 'Please confirm that your evidence is accurate and you have permission to share it.',
        ]);
        if ($data['evidence_type'] === 'reference'
            && strcasecmp($data['reference_email'], $request->user()->email) === 0) {
            throw ValidationException::withMessages(['reference_email' => 'Provide a professional reference other than yourself.']);
        }
        foreach ($request->file('evidence_files', []) as $index => $file) {
            $this->validateEvidence($file, $index);
        }

        $storedPaths = [];
        try {
            DB::transaction(function () use ($request, $data, &$storedPaths) {
                // Lock the same account as profile editing and admin moderation.
                $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
                abort_unless($user->role === 'evaluator' && ! $user->is_suspended, 403);
                if (isset($data['profile'])) {
                    $user->fill($this->profileAttributes($data['profile']));
                }
                if (! $this->profileComplete($user)) {
                    $errors = ['profile' => 'Complete the professional details in this application before submitting.'];
                    foreach ($this->missingProfileFields($user) as $field => $label) {
                        $errors['profile.'.$field] = $label.' is required to submit your application.';
                    }
                    throw ValidationException::withMessages($errors);
                }
                $latest = $user->evaluatorVerifications()->latest('id')->first();
                if ($latest?->status === 'pending' && $latest->matchesProfile($user)) {
                    throw ValidationException::withMessages(['verification' => 'Your application is already awaiting review. Please wait for the admin decision.']);
                }

                $attributes = collect($data)->only([
                    'background_type', 'organization', 'background_summary',
                    'work_sample_url', 'work_sample_explanation', 'sample_review', 'evidence_type',
                ])->all();
                if (in_array($data['evidence_type'], ['portfolio', 'reference'], true)) {
                    $attributes['evidence_explanation'] = $data['evidence_explanation'];
                }
                if ($data['evidence_type'] === 'portfolio') {
                    $attributes['evidence_url'] = $data['evidence_url'];
                }
                if ($data['evidence_type'] === 'reference') {
                    foreach (['reference_name', 'reference_email', 'reference_relationship'] as $field) {
                        $attributes[$field] = $data[$field];
                    }
                }
                $verification = $user->evaluatorVerifications()->create([
                    ...$attributes, 'attestation' => true, 'status' => 'pending',
                    'profile_snapshot' => $user->evaluatorProfileSnapshot(),
                    'profile_fingerprint' => $user->evaluatorProfileFingerprint(),
                ]);
                foreach ($request->file('evidence_files', []) as $file) {
                    $path = $file->store('evaluator-evidence/'.$user->id.'/'.$verification->id, 'local');
                    if (! $path) {
                        throw ValidationException::withMessages(['evidence_files' => 'The evidence could not be saved. Please try again.']);
                    }
                    $storedPaths[] = $path;
                    $verification->evidence()->create([
                        'path' => $path,
                        'original_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
                        'mime_type' => $file->getMimeType(), 'size' => $file->getSize(),
                    ]);
                }
                $user->forceFill(['evaluator_status' => 'pending', 'accepting_requests' => false, 'moderation_note' => null])->save();
                User::where('role', 'admin')->where('is_suspended', false)->each(function (User $admin) use ($user) {
                    $admin->notifyActivity('Evaluator evidence awaiting review', $user->name.' submitted professional evidence and a sample review.', route('admin.evaluators.show', $user));
                });
                $user->notifyActivity('Evaluator verification submitted', 'Your evidence and sample review are awaiting admin review. Your profile stays hidden until approval.', route('account.evaluator-verification.show'));
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return redirect()->route('account.evaluator-verification.show')->with('success', 'Your application has been submitted and is under review.');
    }

    public function download(Request $request, EvaluatorEvidence $evidence): StreamedResponse
    {
        $ownerId = $evidence->verification->user_id;
        abort_unless($request->user()->isAdmin() || (int) $ownerId === (int) $request->user()->id, 403);
        abort_unless(Storage::disk('local')->exists($evidence->path), 404);

        $response = Storage::disk('local')->download($evidence->path, $evidence->original_name, [
            'Content-Type' => $evidence->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);

        if ($request->isMethod('GET')) {
            SensitiveFileAudit::record($request->user(), 'evaluator_evidence', (int) $evidence->id,
                verificationId: (int) $evidence->evaluator_verification_id);
        }

        return $response;
    }

    private function profileComplete(User $user): bool
    {
        return $this->missingProfileFields($user) === [];
    }

    private function missingProfileFields(User $user): array
    {
        $missing = [];
        if (blank(trim(strip_tags($user->name ?? '')))) {
            $missing['name'] = 'Display name';
        }
        if (blank(trim(strip_tags($user->headline ?? '')))) {
            $missing['headline'] = 'Professional headline';
        }
        if (mb_strlen(trim(strip_tags($user->bio ?? ''))) < 30) {
            $missing['bio'] = 'About you (at least 30 characters)';
        }
        if (! $this->validProfileChoices($user->expertise, AccountController::EXPERTISE_OPTIONS, 6)) {
            $missing['expertise'] = 'Areas of expertise';
        }
        if (! $this->validProfileChoices($user->review_modes, ['online', 'on-site'], 2)) {
            $missing['review_modes'] = 'Review mode (online or on-site)';
        }

        return $missing;
    }

    private function validProfileChoices(mixed $values, array $options, int $maximum): bool
    {
        return is_array($values) && array_is_list($values) && count($values) >= 1 && count($values) <= $maximum
            && count(array_filter($values, fn ($value) => is_string($value) && in_array($value, $options, true))) === count($values)
            && count(array_unique($values)) === count($values);
    }

    private function sanitizeProfile(Request $request): void
    {
        $profile = $request->input('profile');
        if (! is_array($profile)) {
            return;
        }
        foreach (['name', 'headline', 'city', 'bio'] as $field) {
            if (is_string($profile[$field] ?? null)) {
                $profile[$field] = trim(strip_tags($profile[$field]));
            }
        }
        if (is_string($profile['skills'] ?? null)) {
            $profile['skills'] = trim(strip_tags($profile['skills']));
        } elseif (is_array($profile['skills'] ?? null)) {
            $profile['skills'] = array_map(fn ($skill) => is_string($skill) ? trim(strip_tags($skill)) : $skill, $profile['skills']);
        }
        $request->merge(['profile' => $profile]);
    }

    private function profileRules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'profile' => [$presence, 'array:name,headline,bio,expertise,review_modes,experience_years,capacity,city,portfolio_url,skills'],
            'profile.name' => [$presence, 'string', 'max:100'],
            'profile.headline' => [$presence, 'string', 'max:180'],
            'profile.bio' => [$presence, 'string', 'min:30', 'max:5000'],
            'profile.expertise' => [$presence, 'array', 'min:1', 'max:6'],
            'profile.expertise.*' => ['required', 'distinct', Rule::in(AccountController::EXPERTISE_OPTIONS)],
            'profile.review_modes' => [$presence, 'array', 'min:1', 'max:2'],
            'profile.review_modes.*' => ['required', 'distinct', Rule::in(['online', 'on-site'])],
            'profile.experience_years' => [$presence, 'integer', 'min:0', 'max:60'],
            'profile.capacity' => [$presence, 'integer', 'min:1', 'max:10'],
            'profile.city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'profile.portfolio_url' => ['sometimes', 'nullable', 'url:http,https', 'max:500'],
            'profile.skills' => ['sometimes', 'nullable', function ($attribute, $value, $fail) {
                if (! is_string($value) && ! is_array($value)) {
                    $fail('Please enter skills separated by commas.');

                    return;
                }
                if (is_string($value) && mb_strlen($value) > 1000) {
                    $fail('The skills field must be no longer than 1,000 characters.');

                    return;
                }
                $skills = is_array($value) ? $value : explode(',', $value);
                if (count($skills) > 30) {
                    $fail('Please enter no more than 30 skills.');

                    return;
                }
                foreach ($skills as $skill) {
                    if (! is_string($skill) || mb_strlen(trim($skill)) > 80) {
                        $fail('Each skill must be text no longer than 80 characters.');

                        return;
                    }
                }
            }],
        ];
    }

    private function profileAttributes(array $profile): array
    {
        $attributes = collect($profile)->only([
            'name', 'headline', 'bio', 'expertise', 'review_modes', 'experience_years', 'capacity', 'city', 'portfolio_url',
        ])->all();
        foreach (['expertise', 'review_modes'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = array_values($attributes[$field]);
            }
        }
        foreach (['experience_years', 'capacity'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = (int) $attributes[$field];
            }
        }
        if (array_key_exists('skills', $profile)) {
            $skills = is_array($profile['skills']) ? $profile['skills'] : explode(',', $profile['skills'] ?? '');
            $attributes['skills'] = array_values(array_unique(array_filter(array_map('trim', $skills), fn ($skill) => $skill !== '')));
        }

        return $attributes;
    }

    private function validateEvidence(UploadedFile $file, int $index): void
    {
        $handle = @fopen($file->getRealPath(), 'rb');
        $header = $handle ? fread($handle, 8) : false;
        if ($handle) {
            fclose($handle);
        }
        $extension = strtolower($file->getClientOriginalExtension());
        $valid = match ($file->getMimeType()) {
            'application/pdf' => $extension === 'pdf' && $this->hasPdfStructure($file, $header ?: ''),
            'image/png' => $extension === 'png' && $header === "\x89PNG\r\n\x1a\n" && @getimagesize($file->getRealPath()) !== false,
            'image/jpeg' => in_array($extension, ['jpg', 'jpeg'], true) && str_starts_with($header ?: '', "\xff\xd8\xff") && @getimagesize($file->getRealPath()) !== false,
            default => false,
        };
        if (! $valid) {
            throw ValidationException::withMessages(['evidence_files.'.$index => 'Upload a valid PDF, JPG, or PNG file.']);
        }
    }

    private function hasPdfStructure(UploadedFile $file, string $header): bool
    {
        if (! preg_match('/^%PDF-[12]\.\d/', $header)) {
            return false;
        }
        $contents = file_get_contents($file->getRealPath());

        return is_string($contents)
            && preg_match('/\d+\s+\d+\s+obj\b/', $contents) === 1
            && str_contains($contents, 'endobj')
            && preg_match('/%%EOF\s*$/', $contents) === 1;
    }
}
