@extends('layouts.app')
@section('title', 'Generate OMR Sheet - OMR Sheet Checker')

@section('content')
    <div class="mb-6">
        <a href="{{ route('dashboard') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Back to Dashboard</a>
        <h1 class="text-2xl font-bold text-gray-900 mt-2">Generate Bubble Sheet Template</h1>
        <p class="text-gray-500 mt-1">Configure and print a bubble sheet for students to fill out.</p>
    </div>

    <form action="{{ route('bubble-sheet.generate') }}" method="GET" target="_blank" id="template-form">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Sheet Configuration</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                {{-- Optional: Link to an existing Answer Key --}}
                <div class="md:col-span-2">
                    <label for="answer_key_id" class="block text-sm font-medium text-gray-700 mb-1">
                        From Answer Key <span class="text-gray-400">(optional)</span>
                    </label>
                    <select name="answer_key_id" id="answer_key_id"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm border px-3 py-2">
                        <option value="">-- Create custom template --</option>
                        @foreach ($answerKeys as $key)
                            <option value="{{ $key->id }}" data-total="{{ $key->total_items }}"
                                data-choices="{{ $key->choices_per_item }}" data-name="{{ $key->name }}"
                                data-subject="{{ $key->subject }}">
                                {{ $key->name }} ({{ $key->total_items }} items, {{ $key->choices_per_item }} choices)
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-400 mt-1">Selecting an answer key will auto-fill the settings below.</p>
                </div>

                <div>
                    <label for="title" class="block text-sm font-medium text-gray-700 mb-1">Exam Title</label>
                    <input type="text" name="title" id="title" value="{{ old('title') }}"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm border px-3 py-2"
                        placeholder="e.g. Midterm Exam 2026">
                    @error('title')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="subject" class="block text-sm font-medium text-gray-700 mb-1">Subject</label>
                    <input type="text" name="subject" id="subject" value="{{ old('subject') }}"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm border px-3 py-2"
                        placeholder="e.g. Mathematics">
                    @error('subject')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="total_items" class="block text-sm font-medium text-gray-700 mb-1">
                        Total Items <span class="text-red-500">*</span>
                    </label>
                    <select name="total_items" id="total_items"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm border px-3 py-2">
                        <option value="30" {{ old('total_items', 50) == 30 ? 'selected' : '' }}>30 Items</option>
                        <option value="50" {{ old('total_items', 50) == 50 ? 'selected' : '' }}>50 Items</option>
                    </select>
                    @error('total_items')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="choices_per_item" class="block text-sm font-medium text-gray-700 mb-1">
                        Choices per Item <span class="text-red-500">*</span>
                    </label>
                    <select name="choices_per_item" id="choices_per_item"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm border px-3 py-2">
                        @for ($i = 2; $i <= 5; $i++)
                            <option value="{{ $i }}" {{ old('choices_per_item', 4) == $i ? 'selected' : '' }}>
                                {{ $i }} (A{{ $i >= 2 ? '-' . chr(64 + $i) : '' }})
                            </option>
                        @endfor
                    </select>
                    @error('choices_per_item')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="columns" class="block text-sm font-medium text-gray-700 mb-1">
                        Number of Columns <span class="text-red-500">*</span>
                    </label>
                    <select name="columns" id="columns" disabled
                        class="w-full rounded-lg border-gray-300 shadow-sm bg-gray-100 text-gray-600 cursor-not-allowed focus:border-indigo-500 focus:ring-indigo-500 text-sm border px-3 py-2">
                        <option value="2" {{ old('columns', 2) == 2 ? 'selected' : '' }}>2 Columns</option>
                        <option value="3" {{ old('columns', 3) == 3 ? 'selected' : '' }}>3 Columns</option>
                    </select>
                    <p class="text-xs text-gray-400 mt-1">Auto: 30 items = 2 columns, 50 items = 3 columns.</p>
                    @error('columns')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>


                <div>
                    <label for="id_digits" class="block text-sm font-medium text-gray-700 mb-1">Student ID Digits <span
                            class="text-red-500">*</span></label>
                    <input type="number" name="id_digits" id="id_digits" value="{{ old('id_digits', 7) }}" min="1"
                        max="15" required
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm border px-3 py-2">
                    <p class="text-xs text-gray-400 mt-1">Number of digit columns in the Student ID bubble grid (scannable).
                    </p>
                    @error('id_digits')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="copies" class="block text-sm font-medium text-gray-700 mb-1">Number of Copies <span
                            class="text-red-500">*</span></label>
                    <input type="number" name="copies" id="copies" value="{{ old('copies', 1) }}" min="1"
                        max="10" required
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm border px-3 py-2">
                    <p class="text-xs text-gray-400 mt-1">Each copy will be on a separate page.</p>
                    @error('copies')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>

        {{-- Preview / Instructions --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-3">Instructions</h2>
            <ul class="list-disc list-inside text-sm text-gray-600 space-y-1">
                <li>The generated sheet will open in a new tab ready for printing.</li>
                <li>Use <strong>Ctrl+P</strong> (or <strong>Cmd+P</strong> on Mac) to print.</li>
                <li>For best scanning results, print on white A4 or Letter paper.</li>
                <li>Students should fill bubbles completely using a dark pen or pencil.</li>
                <li>Corner markers are included to help with alignment during scanning.</li>
            </ul>
        </div>

        <div class="flex justify-end space-x-3">
            <a href="{{ route('dashboard') }}"
                class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">
                Cancel
            </a>
            <button type="submit"
                class="px-6 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition shadow-sm inline-flex items-center">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                </svg>
                Generate &amp; Print
            </button>
        </div>
    </form>

    @push('scripts')
        <script>
            const answerKeyEl = document.getElementById('answer_key_id');
            const totalItemsEl = document.getElementById('total_items');
            const choicesPerItemEl = document.getElementById('choices_per_item');
            const titleEl = document.getElementById('title');
            const subjectEl = document.getElementById('subject');
            const columnsEl = document.getElementById('columns');

            function syncColumnsFromTotal() {
                columnsEl.value = totalItemsEl.value === '50' ? '3' : '2';
            }

            answerKeyEl.addEventListener('change', function() {
                const selected = this.options[this.selectedIndex];

                if (this.value) {
                    totalItemsEl.value = selected.dataset.total;
                    choicesPerItemEl.value = selected.dataset.choices;
                    titleEl.value = selected.dataset.name || '';
                    subjectEl.value = selected.dataset.subject || '';
                    totalItemsEl.disabled = true;
                    choicesPerItemEl.disabled = true;
                } else {
                    totalItemsEl.disabled = false;
                    choicesPerItemEl.disabled = false;
                }

                syncColumnsFromTotal();
            });

            totalItemsEl.addEventListener('change', syncColumnsFromTotal);

            document.getElementById('template-form').addEventListener('submit', function() {
                totalItemsEl.disabled = false;
                choicesPerItemEl.disabled = false;
                columnsEl.disabled = false; // submit columns value
                syncColumnsFromTotal();
            });

            syncColumnsFromTotal();
        </script>
    @endpush
@endsection
