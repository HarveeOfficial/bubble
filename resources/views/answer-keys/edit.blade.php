@extends('layouts.app')
@section('title', 'Edit Answer Key - ' . $answerKey->name)

@section('content')
<div class="mb-6">
    <a href="{{ route('answer-keys.show', $answerKey) }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Back to {{ $answerKey->name }}</a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2">Edit Answer Key</h1>
</div>

<form action="{{ route('answer-keys.update', $answerKey) }}" method="POST" id="answer-key-form">
    @csrf
    @method('PUT')

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Exam Details</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Name <span class="text-red-500">*</span></label>
                <input type="text" name="name" id="name" value="{{ old('name', $answerKey->name) }}" required
                       class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm border px-3 py-2">
                @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="subject" class="block text-sm font-medium text-gray-700 mb-1">Subject</label>
                <input type="text" name="subject" id="subject" value="{{ old('subject', $answerKey->subject) }}"
                       class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm border px-3 py-2">
                @error('subject') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="total_items" class="block text-sm font-medium text-gray-700 mb-1">Total Items <span class="text-red-500">*</span></label>
                <input type="number" name="total_items" id="total_items" value="{{ old('total_items', $answerKey->total_items) }}" min="1" max="200" required
                       class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm border px-3 py-2">
                @error('total_items') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="choices_per_item" class="block text-sm font-medium text-gray-700 mb-1">Choices per Item <span class="text-red-500">*</span></label>
                <select name="choices_per_item" id="choices_per_item"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm border px-3 py-2">
                    @for($i = 2; $i <= 5; $i++)
                        <option value="{{ $i }}" {{ old('choices_per_item', $answerKey->choices_per_item) == $i ? 'selected' : '' }}>{{ $i }} (A-{{ chr(64 + $i) }})</option>
                    @endfor
                </select>
                @error('choices_per_item') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Answer Key</h2>

        @error('answers') <p class="text-red-500 text-sm mb-4">{{ $message }}</p> @enderror

        <div id="answers-container" class="space-y-3"></div>
    </div>

    <div class="flex justify-end space-x-3">
        <a href="{{ route('answer-keys.show', $answerKey) }}" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">
            Cancel
        </a>
        <button type="submit" class="px-6 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition shadow-sm">
            Update Answer Key
        </button>
    </div>
</form>

@push('scripts')
<script>
    const choiceLabels = ['A', 'B', 'C', 'D', 'E'];
    const existingAnswers = @json(old('answers', $answerKey->answers));

    function renderAnswers() {
        const total = parseInt(document.getElementById('total_items').value) || 0;
        const choices = parseInt(document.getElementById('choices_per_item').value) || 4;
        const container = document.getElementById('answers-container');
        container.innerHTML = '';

        for (let i = 1; i <= total; i++) {
            const row = document.createElement('div');
            row.className = 'flex items-center space-x-4 py-2 px-3 rounded-lg hover:bg-gray-50';

            let html = `<span class="w-8 text-sm font-medium text-gray-500 text-right">${i}.</span>`;
            html += '<div class="flex space-x-2">';
            for (let c = 0; c < choices; c++) {
                const label = choiceLabels[c];
                const checked = (existingAnswers[i] === label) ? 'checked' : '';
                html += `
                    <label class="relative cursor-pointer">
                        <input type="radio" name="answers[${i}]" value="${label}" ${checked} required
                               class="sr-only peer">
                        <span class="flex items-center justify-center w-9 h-9 rounded-full border-2 text-sm font-medium
                                     peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:border-indigo-600
                                     border-gray-300 text-gray-600 hover:border-indigo-400 transition">
                            ${label}
                        </span>
                    </label>
                `;
            }
            html += '</div>';
            row.innerHTML = html;
            container.appendChild(row);
        }
    }

    document.getElementById('total_items').addEventListener('input', renderAnswers);
    document.getElementById('choices_per_item').addEventListener('change', renderAnswers);
    renderAnswers();
</script>
@endpush
@endsection
