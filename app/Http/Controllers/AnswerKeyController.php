<?php

namespace App\Http\Controllers;

use App\Models\AnswerKey;
use Illuminate\Http\Request;

class AnswerKeyController extends Controller
{
    public function index()
    {
        $answerKeys = AnswerKey::withCount('exams')->latest()->paginate(10);
        return view('answer-keys.index', compact('answerKeys'));
    }

    public function create()
    {
        return view('answer-keys.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'subject' => 'nullable|string|max:255',
            'total_items' => 'required|integer|min:1|max:200',
            'choices_per_item' => 'required|integer|min:2|max:5',
            'answers' => 'required|array',
            'answers.*' => 'required|string|in:A,B,C,D,E',
        ]);

        // Re-index answers starting from 1
        $answers = [];
        foreach ($validated['answers'] as $index => $answer) {
            $answers[$index] = $answer;
        }

        $answerKey = AnswerKey::create([
            'name' => $validated['name'],
            'subject' => $validated['subject'],
            'total_items' => $validated['total_items'],
            'choices_per_item' => $validated['choices_per_item'],
            'answers' => $answers,
        ]);

        return redirect()->route('answer-keys.show', $answerKey)
            ->with('success', 'Answer key created successfully.');
    }

    public function show(AnswerKey $answerKey)
    {
        $answerKey->load(['exams' => function ($query) {
            $query->latest()->limit(20);
        }]);
        return view('answer-keys.show', compact('answerKey'));
    }

    public function edit(AnswerKey $answerKey)
    {
        return view('answer-keys.edit', compact('answerKey'));
    }

    public function update(Request $request, AnswerKey $answerKey)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'subject' => 'nullable|string|max:255',
            'total_items' => 'required|integer|min:1|max:200',
            'choices_per_item' => 'required|integer|min:2|max:5',
            'answers' => 'required|array',
            'answers.*' => 'required|string|in:A,B,C,D,E',
        ]);

        $answers = [];
        foreach ($validated['answers'] as $index => $answer) {
            $answers[$index] = $answer;
        }

        $answerKey->update([
            'name' => $validated['name'],
            'subject' => $validated['subject'],
            'total_items' => $validated['total_items'],
            'choices_per_item' => $validated['choices_per_item'],
            'answers' => $answers,
        ]);

        return redirect()->route('answer-keys.show', $answerKey)
            ->with('success', 'Answer key updated successfully.');
    }

    public function destroy(AnswerKey $answerKey)
    {
        $answerKey->delete();
        return redirect()->route('answer-keys.index')
            ->with('success', 'Answer key deleted successfully.');
    }
}
