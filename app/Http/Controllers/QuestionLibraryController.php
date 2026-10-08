<?php

namespace App\Http\Controllers;

use App\Models\QuestionLibrary;
use App\Models\RiskCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class QuestionLibraryController extends Controller
{
    public function index(Request $request)
    {
        $questions = QuestionLibrary::query()
            ->when($request->filled('module'), fn ($q) => $q->where('module', $request->module))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->when($request->filled('response_type'), fn ($q) => $q->where('response_type', $request->response_type))
            ->when($request->filled('status'), fn ($q) => $q->where('is_active', $request->status === 'active'))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$request->search}%")
                ->orWhere('question_text', 'like', "%{$request->search}%")))
            ->orderBy('module')->orderBy('sort_order')->orderBy('id')
            ->paginate(20)->withQueryString();

        return Inertia::render('Risks/QuestionLibrary/Index', [
            'questions' => $questions,
            'filters' => $request->only('module', 'category', 'response_type', 'status', 'search'),
            'modules' => QuestionLibrary::MODULES,
            'responseTypes' => QuestionLibrary::RESPONSE_TYPES,
            'categories' => RiskCategory::orderBy('sort_order')->pluck('name')
                ->merge(QuestionLibrary::whereNotNull('category')->distinct()->pluck('category'))
                ->unique()->values(),
            'summary' => [
                'total' => QuestionLibrary::count(),
                'active' => QuestionLibrary::where('is_active', true)->count(),
                'by_module' => QuestionLibrary::selectRaw('module, count(*) as n')->groupBy('module')->pluck('n', 'module'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $question = QuestionLibrary::create($this->validated($request) + [
            'organization_id' => $request->user()->organization_id,
        ]);

        return back()->with('success', "Question \"{$question->title}\" added to the library.");
    }

    public function update(Request $request, QuestionLibrary $questionLibrary)
    {
        $questionLibrary->update($this->validated($request));

        return back()->with('success', "Question \"{$questionLibrary->title}\" updated.");
    }

    public function destroy(QuestionLibrary $questionLibrary)
    {
        $questionLibrary->delete();

        return back()->with('success', "Question \"{$questionLibrary->title}\" removed.");
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'question_text' => 'required|string|max:2000',
            'category' => 'nullable|string|max:100',
            'module' => ['required', Rule::in(QuestionLibrary::MODULES)],
            'response_type' => ['required', Rule::in(QuestionLibrary::RESPONSE_TYPES)],
            'response_options' => 'nullable|array|required_if:response_type,multiple_choice',
            'response_options.*' => 'nullable|string|max:255',
            'weight' => 'required|integer|min:1|max:10',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0|max:9999',
        ]);

        // Options only mean something for multiple choice; a scale is always 1–5.
        $data['response_options'] = $data['response_type'] === 'multiple_choice'
            ? array_values(array_filter(array_map(fn ($o) => trim((string) $o), $data['response_options'] ?? []), 'strlen'))
            : null;
        $data['sort_order'] ??= 0;

        return $data;
    }
}
