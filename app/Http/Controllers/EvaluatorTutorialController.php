<?php

namespace App\Http\Controllers;

use App\Models\Tutorial;
use App\Support\TutorialPublishing;
use Illuminate\Http\Request;

class EvaluatorTutorialController extends Controller
{
    private function authorizeEvaluator(Request $request, ?Tutorial $tutorial = null): void
    {
        abort_unless($request->user()?->isEvaluator(), 403);
        TutorialPublishing::authorize($request->user(), $tutorial);
    }

    public function index(Request $request)
    {
        $this->authorizeEvaluator($request);
        $tutorials = Tutorial::where('user_id', $request->user()->id)->latest()->paginate(9)->withQueryString();
        return view('account.tutorials.index', compact('tutorials'));
    }

    public function create(Request $request)
    {
        $this->authorizeEvaluator($request);
        return view('account.tutorials.form', ['tutorial' => null]);
    }

    public function store(Request $request)
    {
        $this->authorizeEvaluator($request);
        $tutorial = TutorialPublishing::save($request);
        return redirect()->route('account.tutorials.index')->with('success', $tutorial->is_published ? 'Your tutorial has been published.' : 'Tutorial saved as a draft.');
    }

    public function edit(Request $request, Tutorial $tutorial)
    {
        $this->authorizeEvaluator($request, $tutorial);
        return view('account.tutorials.form', compact('tutorial'));
    }

    public function update(Request $request, Tutorial $tutorial)
    {
        $this->authorizeEvaluator($request, $tutorial);
        TutorialPublishing::save($request, $tutorial);
        return redirect()->route('account.tutorials.index')->with('success', 'Your tutorial has been updated.');
    }

    public function destroy(Request $request, Tutorial $tutorial)
    {
        $this->authorizeEvaluator($request, $tutorial);
        TutorialPublishing::destroy($request->user(), $tutorial);
        return redirect()->route('account.tutorials.index')->with('success', 'Your tutorial has been deleted.');
    }
}
