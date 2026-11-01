<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use App\Support\ProjectAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function store(Request $request)
    {
        $actor = $request->user();
        ProjectAccess::assertActive($actor);
        $data = $request->validate([
            'reportable_type' => ['required', Rule::in(['project', 'user'])],
            'reportable_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', Rule::in(['spam', 'fake_information', 'inappropriate_content', 'harassment', 'other'])],
            'details' => ['nullable', 'required_if:reason,other', 'string', 'max:3000'],
        ]);
        if ($data['reportable_type'] === 'project') {
            $subject = Project::findOrFail($data['reportable_id']);
            abort_unless((in_array($subject->status, ['approved', 'completed'], true) && $subject->is_public)
                || ProjectAccess::canViewPrivate($subject, $actor)
                || $subject->reviewRequests()->where('evaluator_id', $actor->id)->exists(), 403);
            if (ProjectAccess::isMember($subject, $actor)) {
                throw ValidationException::withMessages(['report' => 'You cannot report your own thesis project.']);
            }
        } else {
            $subject = User::findOrFail($data['reportable_id']);
            abort_unless($subject->isEvaluator(), 404);
            if ((int) $subject->id === (int) $actor->id) {
                throw ValidationException::withMessages(['report' => 'You cannot report your own profile.']);
            }
        }

        DB::transaction(function () use ($actor, $data) {
            // Serialize this user's submissions to prevent duplicate reports on double-click.
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            ProjectAccess::assertActive($actor);
            if (Report::query()->where('user_id', $actor->id)->where('reportable_type', $data['reportable_type'])
                ->where('reportable_id', $data['reportable_id'])->whereIn('status', ['pending', 'under_review'])->exists()) {
                throw ValidationException::withMessages(['report' => 'You already have an open report for this page.']);
            }
            Report::create([...$data, 'user_id' => $actor->id, 'status' => 'pending']);
        });
        User::query()->where('role', 'admin')->where('is_suspended', false)->get()->each(function (User $admin) {
            // No reporter identity is exposed in activity notifications.
            $admin->notifyActivity('New community report', 'A community report is waiting for admin review.', route('admin.index', ['section' => 'reports']));
        });

        return back()->with('success', 'Your report has been sent privately to the admin team. Thank you for helping keep Analista constructive.');
    }
}
