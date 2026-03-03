@extends('layouts.app')
@section('title', 'Edit Exam - ' . ($exam->student_name ?? 'Unknown'))

@section('content')
<div class="mb-6">
    <a href="{{ route('exams.show', $exam) }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Back to Exam</a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2">Edit Exam</h1>
    <p class="text-gray-500 mt-1">Update student info or manually enter/correct answers.</p>
</div>

<form action="{{ route('exams.update', $exam) }}" method="POST">
    @csrf
    @method('PUT')

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Student Information</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label for="student_name" class="block text-sm font-medium text-gray-700 mb-1">Student Name</label>
                <input type="text" name="student_name" id="student_name" value="{{ old('student_name', $exam->student_name) }}"
                       class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm border px-3 py-2">
                @error('student_name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="student_id" class="block text-sm font-medium text-gray-700 mb-1">Student ID</label>
                <input type="text" name="student_id" id="student_id" value="{{ old('student_id', $exam->student_id) }}"
                       class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm border px-3 py-2">
                @error('student_id') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-2">Manual Answers</h2>
        <p class="text-sm text-gray-500 mb-4">
            Manually enter or correct the student's answers. Leave blank for unanswered questions.
            <br>Answer key: <strong>{{ $exam->answerKey->name }}</strong> ({{ $exam->answerKey->total_items }} items)
        </p>

        @error('manual_answers') <p class="text-red-500 text-sm mb-4">{{ $message }}</p> @enderror

        @php
            $answers = old('manual_answers', $exam->detected_answers ?? []);
            $correctAnswers = $exam->answerKey->answers;
            $choiceLabels = ['A', 'B', 'C', 'D', 'E'];
        @endphp

        <div class="space-y-3">
            @for($i = 1; $i <= $exam->answerKey->total_items; $i++)
                <div class="flex items-center space-x-4 py-2 px-3 rounded-lg hover:bg-gray-50">
                    <span class="w-8 text-sm font-medium text-gray-500 text-right">{{ $i }}.</span>

                    <div class="flex space-x-2">
                        @for($c = 0; $c < $exam->answerKey->choices_per_item; $c++)
                            @php $label = $choiceLabels[$c]; @endphp
                            <label class="relative cursor-pointer">
                                <input type="radio" name="manual_answers[{{ $i }}]" value="{{ $label }}"
                                       {{ (isset($answers[$i]) && $answers[$i] === $label) ? 'checked' : '' }}
                                       class="sr-only peer">
                                <span class="flex items-center justify-center w-9 h-9 rounded-full border-2 text-sm font-medium
                                             peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:border-indigo-600
                                             border-gray-300 text-gray-600 hover:border-indigo-400 transition">
                                    {{ $label }}
                                </span>
                            </label>
                        @endfor

                        {{-- Clear button --}}
                        <button type="button" onclick="clearAnswer({{ $i }})"
                                class="flex items-center justify-center w-9 h-9 rounded-full border-2 border-gray-200 text-gray-400 hover:text-red-500 hover:border-red-300 transition text-xs"
                                title="Clear answer">
                            &times;
                        </button>
                    </div>

                    {{-- Show correct answer hint --}}
                    <span class="text-xs text-gray-400 ml-2">
                        Correct: <span class="font-medium text-indigo-600">{{ $correctAnswers[$i] ?? '?' }}</span>
                    </span>
                </div>
            @endfor
        </div>
    </div>

    <div class="flex justify-end space-x-3">
        <a href="{{ route('exams.show', $exam) }}" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">
            Cancel
        </a>
        <button type="submit" class="px-6 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition shadow-sm">
            Save &amp; Re-grade
        </button>
    </div>
</form>

@push('scripts')
<script>
    function clearAnswer(questionNum) {
        const radios = document.querySelectorAll(`input[name="manual_answers[${questionNum}]"]`);
        radios.forEach(r => r.checked = false);
    }
</script>
@endpush
@endsection
