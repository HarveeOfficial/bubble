<?php

namespace App\Http\Controllers;

use App\Models\AnswerKey;
use Illuminate\Http\Request;

class BubbleSheetController extends Controller
{
    /**
     * Show the form to configure a bubble sheet template.
     */
    public function templateForm()
    {
        $answerKeys = AnswerKey::latest()->get();
        return view('bubble-sheet.template-form', compact('answerKeys'));
    }

    /**
     * Generate and display a printable bubble sheet.
     */
    public function generate(Request $request)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'subject' => 'nullable|string|max:255',
            'total_items' => 'required|integer|min:1|max:200',
            'choices_per_item' => 'required|integer|min:2|max:5',
            'copies' => 'required|integer|min:1|max:10',
            'columns' => 'required|integer|in:1,2,3',
            'id_digits' => 'required|integer|min:1|max:15',
            'answer_key_id' => 'nullable|exists:answer_keys,id',
        ]);

        // If an answer key is selected, override total_items and choices_per_item
        $answerKey = null;
        if (!empty($validated['answer_key_id'])) {
            $answerKey = AnswerKey::find($validated['answer_key_id']);
            $validated['total_items'] = $answerKey->total_items;
            $validated['choices_per_item'] = $answerKey->choices_per_item;
            $validated['title'] = $validated['title'] ?: $answerKey->name;
            $validated['subject'] = $validated['subject'] ?: $answerKey->subject;
        }

        return view('bubble-sheet.print', [
            'title' => $validated['title'] ?? 'Exam',
            'subject' => $validated['subject'] ?? '',
            'totalItems' => $validated['total_items'],
            'choicesPerItem' => $validated['choices_per_item'],
            'copies' => $validated['copies'],
            'columns' => $validated['columns'],
            'idDigits' => $validated['id_digits'],
            'answerKey' => $answerKey,
        ]);
    }
}
