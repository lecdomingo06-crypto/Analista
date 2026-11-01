<?php

namespace App\Http\Controllers;

use App\Models\{Project, ReviewRequest, Tutorial, User};
use Illuminate\Http\Request;

class PublicController extends Controller
{
    private function reviewers() { return User::with('latestEvaluatorVerification')->withAvg('ratingsReceived', 'rating')->withCount('ratingsReceived')->where('role', 'evaluator')->where('evaluator_status', 'approved')->where('is_suspended', false); }
    public function home(Request $request)
    {
        $storedIds = $request->session()->get('home.featured_evaluator_ids', []);
        $featuredIds = collect(is_array($storedIds) ? $storedIds : [])
            ->filter(fn ($id) => is_int($id) && $id > 0)->unique()->take(3)->values()->all();
        $positions = array_flip($featuredIds);
        $reviewers = $this->reviewers()->where('accepting_requests', true)->whereIn('id', $featuredIds)->get()
            ->sortBy(fn (User $evaluator) => $positions[$evaluator->id])->values();

        if ($reviewers->count() < 3) {
            $replacements = $this->reviewers()->where('accepting_requests', true)
                ->whereNotIn('id', $reviewers->modelKeys())->inRandomOrder()->limit(3 - $reviewers->count())->get();
            $reviewers = $reviewers->merge($replacements);
        }

        $request->session()->put('home.featured_evaluator_ids', $reviewers->modelKeys());
        $tutorials = Tutorial::where('is_published', true)->whereNotNull('youtube_video_id')->orderByDesc('featured')->orderBy('id')->take(6)->get();
        $stats = ['evaluators' => $this->reviewers()->count(), 'projects' => Project::whereIn('status', ['approved', 'completed'])->where('is_public', true)->count(), 'tutorials' => Tutorial::where('is_published', true)->whereNotNull('youtube_video_id')->count(), 'reviews' => ReviewRequest::where('status', 'completed')->count()];
        return view('home', compact('reviewers', 'tutorials', 'stats'));
    }
    public function evaluators(Request $request)
    {
        $approvedCount = $this->reviewers()->count();
        $query = $this->reviewers();
        if ($request->filled('q')) {
            $term = mb_substr($request->string('q')->toString(), 0, 100);
            $query->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('headline', 'like', "%{$term}%")->orWhere('skills', 'like', "%{$term}%"));
        }
        if ($request->filled('category')) $query->whereJsonContains('expertise', $request->query('category'));
        if ($request->filled('experience')) $query->where('experience_years', '>=', min(60, max(0, (int)$request->query('experience'))));
        if ($request->boolean('available')) $query->where('accepting_requests', true);
        match ($request->query('sort')) {
            'experience' => $query->orderByDesc('experience_years'), 'rating' => $query->orderByDesc('ratings_received_avg_rating'),
            'newest' => $query->latest(), default => $query->orderByDesc('accepting_requests')->orderByDesc('experience_years'),
        };
        $evaluators = $query->paginate(9)->withQueryString();
        return view('evaluators.index', compact('evaluators', 'approvedCount'));
    }
    public function evaluator(User $evaluator)
    {
        abort_unless($evaluator->isEvaluator() && !$evaluator->is_suspended, 404);
        $evaluator->loadAvg('ratingsReceived', 'rating')->loadCount('ratingsReceived');
        $ratingCounts = $evaluator->ratingsReceived()->select('rating')->selectRaw('COUNT(*) as total')
            ->groupBy('rating')->pluck('total', 'rating');
        $ratingDistribution = collect(range(5, 1))->mapWithKeys(fn ($stars) => [$stars => (int) ($ratingCounts[$stars] ?? 0)])->all();
        $ratings = $evaluator->ratingsReceived()->with('author')->latest()->paginate(6);
        $projects = auth()->check() ? Project::where('status', 'approved')->where('review_mode', Project::REVIEW_MODE_REQUEST_ONLY)->where(fn ($q) => $q->where('user_id', auth()->id())->orWhereHas('members', fn ($m) => $m->where('users.id', auth()->id())))->with('documents')->get() : collect();
        return view('evaluators.show', compact('evaluator', 'ratings', 'ratingDistribution', 'projects'));
    }
    public function tutorials(Request $request)
    {
        $query = Tutorial::where('is_published', true)->whereNotNull('youtube_video_id')->with('author');
        if ($request->filled('q')) {
            $term = mb_substr($request->string('q')->toString(), 0, 100);
            $query->where(fn ($q) => $q->where('title', 'like', "%{$term}%")->orWhere('excerpt', 'like', "%{$term}%")->orWhere('video_creator', 'like', "%{$term}%"));
        }
        if ($request->filled('category')) $query->where('category', $request->query('category'));
        if ($request->boolean('saved') && auth()->check()) $query->whereHas('bookmarkedBy', fn ($q) => $q->where('users.id', auth()->id()));
        $tutorials = $query->orderByDesc('featured')->orderBy('id')->paginate(10)->withQueryString();
        $categories = Tutorial::where('is_published', true)->whereNotNull('youtube_video_id')->distinct()->orderBy('category')->pluck('category');
        return view('tutorials.index', compact('tutorials', 'categories'));
    }
    public function tutorial(Tutorial $tutorial)
    {
        abort_unless($tutorial->is_published || auth()->user()?->isAdmin(), 404);
        $saved = auth()->check() && auth()->user()->bookmarks()->where('tutorials.id', $tutorial->id)->exists();
        return view('tutorials.show', compact('tutorial', 'saved'));
    }
    public function guidelines() { return view('guidelines'); }
    public function prepareReview(Request $request)
    {
        $data = $request->validate(['project_id' => 'required|integer|exists:projects,id', 'evaluator_id' => 'required|integer|exists:users,id']);
        $project = Project::findOrFail($data['project_id']);
        abort_unless($project->status === 'approved' && ($project->user_id === $request->user()->id || $project->members()->where('users.id', $request->user()->id)->exists()), 403);
        if ($project->usesVolunteerReviews()) {
            return redirect()->to(route('account.projects.show', $project).'#volunteer-reviews')->with('error', 'This project is open to volunteer evaluators. Switch to By request only in Edit project to send invitations.');
        }
        return redirect()->to(route('account.projects.show', ['project' => $project, 'evaluator' => $data['evaluator_id']]).'#request-review');
    }
}
