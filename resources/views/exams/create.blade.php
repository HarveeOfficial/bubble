@extends('layouts.app')
@section('title', 'Scan OMR Sheet - OMR Sheet Checker')

@section('content')
<div class="mb-6">
    <a href="{{ route('exams.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Back to Exams</a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2">Scan Bubble Sheet</h1>
    <p class="text-gray-500 mt-1">Upload bubble sheet images to grade them automatically. Student IDs are auto-detected from the bubble grid.</p>
</div>

@if($answerKeys->isEmpty())
    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 text-center">
        <svg class="w-12 h-12 mx-auto mb-3 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
        </svg>
        <h3 class="text-lg font-medium text-yellow-800 mb-2">No Answer Keys Available</h3>
        <p class="text-yellow-700 mb-4">You need to create an answer key before scanning bubble sheets.</p>
        <a href="{{ route('answer-keys.create') }}" class="inline-flex items-center px-4 py-2 bg-yellow-600 text-white text-sm font-medium rounded-lg hover:bg-yellow-700 transition">
            Create Answer Key
        </a>
    </div>
@else
    {{-- Upload Mode Tabs --}}
    <div class="flex space-x-1 bg-gray-100 rounded-lg p-1 mb-6 max-w-md">
        <button type="button" id="tab-single" onclick="switchMode('single')"
                class="flex-1 px-4 py-2 text-sm font-medium rounded-md transition focus:outline-none bg-white text-gray-900 shadow-sm">
            Single Upload
        </button>
        <button type="button" id="tab-batch" onclick="switchMode('batch')"
                class="flex-1 px-4 py-2 text-sm font-medium rounded-md transition focus:outline-none text-gray-500 hover:text-gray-700">
            Batch Upload
        </button>
    </div>

    {{-- ===== SINGLE UPLOAD FORM ===== --}}
    <form action="{{ route('exams.store') }}" method="POST" enctype="multipart/form-data" id="single-form" class="space-y-6">
        @csrf

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Student Information</h2>
            <p class="text-xs text-gray-400 mb-4">Leave blank to auto-detect Student ID from the bubble grid on the sheet.</p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="student_name" class="block text-sm font-medium text-gray-700 mb-1">Student Name</label>
                    <input type="text" name="student_name" id="student_name" value="{{ old('student_name') }}"
                           class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm border px-3 py-2"
                           placeholder="e.g. Juan Dela Cruz">
                    @error('student_name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="student_id" class="block text-sm font-medium text-gray-700 mb-1">Student ID <span class="text-gray-400 font-normal">(auto-detected)</span></label>
                    <input type="text" name="student_id" id="student_id" value="{{ old('student_id') }}"
                           class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm border px-3 py-2"
                           placeholder="Auto-detected from bubble grid">
                    @error('student_id') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Answer Key</h2>

            <div>
                <label for="single_answer_key_id" class="block text-sm font-medium text-gray-700 mb-1">Select Answer Key <span class="text-red-500">*</span></label>
                <select name="answer_key_id" id="single_answer_key_id" required
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm border px-3 py-2">
                    <option value="">-- Choose an answer key --</option>
                    @foreach($answerKeys as $key)
                        <option value="{{ $key->id }}" {{ old('answer_key_id', request('answer_key_id')) == $key->id ? 'selected' : '' }}>
                            {{ $key->name }} ({{ $key->total_items }} items{{ $key->subject ? ' - ' . $key->subject : '' }})
                        </option>
                    @endforeach
                </select>
                @error('answer_key_id') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Bubble Sheet Image</h2>

            <div>
                <label for="bubble_sheet" class="block text-sm font-medium text-gray-700 mb-2">Upload Image <span class="text-red-500">*</span></label>

                <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-xl hover:border-indigo-400 transition" id="single-drop-zone">
                    <div class="space-y-2 text-center">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <div class="flex text-sm text-gray-600 justify-center">
                            <label for="bubble_sheet" class="relative cursor-pointer rounded-md font-medium text-indigo-600 hover:text-indigo-500">
                                <span>Upload a file</span>
                                <input id="bubble_sheet" name="bubble_sheet" type="file" accept="image/*" required class="sr-only" onchange="previewSingleImage(this)">
                            </label>
                            <p class="pl-1">or drag and drop</p>
                        </div>
                        <p class="text-xs text-gray-500">PNG, JPG, GIF, BMP, WEBP up to 10MB</p>
                    </div>
                </div>

                <div id="single-preview" class="mt-4 hidden">
                    <img id="single-preview-img" src="" alt="Preview" class="max-h-64 rounded-lg border border-gray-200 mx-auto">
                    <p id="single-preview-name" class="text-sm text-gray-500 text-center mt-2"></p>
                </div>

                @error('bubble_sheet') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="mt-4 p-4 bg-blue-50 rounded-lg">
                <h4 class="text-sm font-medium text-blue-800 mb-1">Tips for best results:</h4>
                <ul class="text-xs text-blue-700 space-y-1 list-disc list-inside">
                    <li>Ensure the bubble sheet is well-lit and flat</li>
                    <li>Avoid shadows and glare on the image</li>
                    <li>Make sure filled bubbles are dark and complete</li>
                    <li>Student ID is auto-detected from the bubble grid on the sheet</li>
                </ul>
            </div>
        </div>

        <div class="flex justify-end space-x-3">
            <a href="{{ route('exams.index') }}" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">
                Cancel
            </a>
            <button type="submit" class="px-6 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition shadow-sm">
                Process Bubble Sheet
            </button>
        </div>
    </form>

    {{-- ===== BATCH UPLOAD FORM ===== --}}
    <form action="{{ route('exams.store-batch') }}" method="POST" enctype="multipart/form-data" id="batch-form" class="space-y-6 hidden">
        @csrf

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Answer Key</h2>

            <div>
                <label for="batch_answer_key_id" class="block text-sm font-medium text-gray-700 mb-1">Select Answer Key <span class="text-red-500">*</span></label>
                <select name="answer_key_id" id="batch_answer_key_id" required
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm border px-3 py-2">
                    <option value="">-- Choose an answer key --</option>
                    @foreach($answerKeys as $key)
                        <option value="{{ $key->id }}" {{ old('answer_key_id', request('answer_key_id')) == $key->id ? 'selected' : '' }}>
                            {{ $key->name }} ({{ $key->total_items }} items{{ $key->subject ? ' - ' . $key->subject : '' }})
                        </option>
                    @endforeach
                </select>
                @error('answer_key_id') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Bubble Sheet Images</h2>
            <p class="text-sm text-gray-500 mb-3">Select multiple images at once. Each image will be processed as a separate exam. Student IDs will be auto-detected from the bubble grid.</p>

            <div>
                <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-xl hover:border-indigo-400 transition cursor-pointer" id="batch-drop-zone" onclick="document.getElementById('bubble_sheets').click()">
                    <div class="space-y-2 text-center">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                        </svg>
                        <div class="flex text-sm text-gray-600 justify-center">
                            <span class="font-medium text-indigo-600">Click to select files</span>
                            <p class="pl-1">or drag and drop</p>
                        </div>
                        <p class="text-xs text-gray-500">PNG, JPG, GIF, BMP, WEBP up to 10MB each &mdash; select multiple files</p>
                        <input id="bubble_sheets" name="bubble_sheets[]" type="file" accept="image/*" multiple required class="sr-only" onchange="previewBatchImages(this)">
                    </div>
                </div>

                @error('bubble_sheets') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                @error('bubble_sheets.*') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            {{-- Batch Preview --}}
            <div id="batch-preview" class="mt-4 hidden">
                <div class="flex items-center justify-between mb-3">
                    <h4 class="text-sm font-medium text-gray-700">
                        Selected: <span id="batch-count" class="text-indigo-600 font-semibold">0</span> file(s)
                    </h4>
                    <button type="button" onclick="clearBatchFiles()" class="text-xs text-red-500 hover:text-red-700 font-medium">Clear All</button>
                </div>
                <div id="batch-thumbs" class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-3"></div>
            </div>

            <div class="mt-4 p-4 bg-emerald-50 rounded-lg">
                <h4 class="text-sm font-medium text-emerald-800 mb-1">Batch processing info:</h4>
                <ul class="text-xs text-emerald-700 space-y-1 list-disc list-inside">
                    <li>Each image is processed as a separate student exam</li>
                    <li>Student IDs are <strong>auto-detected</strong> from the bubble grid on each sheet</li>
                    <li>All sheets are graded against the same answer key</li>
                    <li>Results will appear in the exams list after processing</li>
                </ul>
            </div>
        </div>

        <div class="flex justify-end space-x-3">
            <a href="{{ route('exams.index') }}" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">
                Cancel
            </a>
            <button type="submit" id="batch-submit" class="px-6 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition shadow-sm inline-flex items-center">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                </svg>
                Process All Sheets
            </button>
        </div>
    </form>
@endif

@push('scripts')
<script>
    // ===== Mode Switching =====
    function switchMode(mode) {
        const singleForm = document.getElementById('single-form');
        const batchForm = document.getElementById('batch-form');
        const tabSingle = document.getElementById('tab-single');
        const tabBatch = document.getElementById('tab-batch');

        if (mode === 'single') {
            singleForm.classList.remove('hidden');
            batchForm.classList.add('hidden');
            tabSingle.className = 'flex-1 px-4 py-2 text-sm font-medium rounded-md transition focus:outline-none bg-white text-gray-900 shadow-sm';
            tabBatch.className = 'flex-1 px-4 py-2 text-sm font-medium rounded-md transition focus:outline-none text-gray-500 hover:text-gray-700';
        } else {
            singleForm.classList.add('hidden');
            batchForm.classList.remove('hidden');
            tabBatch.className = 'flex-1 px-4 py-2 text-sm font-medium rounded-md transition focus:outline-none bg-white text-gray-900 shadow-sm';
            tabSingle.className = 'flex-1 px-4 py-2 text-sm font-medium rounded-md transition focus:outline-none text-gray-500 hover:text-gray-700';
        }
    }

    // ===== Single Upload Preview =====
    function previewSingleImage(input) {
        const preview = document.getElementById('single-preview');
        const img = document.getElementById('single-preview-img');
        const name = document.getElementById('single-preview-name');

        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                img.src = e.target.result;
                name.textContent = input.files[0].name;
                preview.classList.remove('hidden');
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    // ===== Batch Upload Preview =====
    function previewBatchImages(input) {
        const preview = document.getElementById('batch-preview');
        const thumbs = document.getElementById('batch-thumbs');
        const count = document.getElementById('batch-count');

        thumbs.innerHTML = '';

        if (input.files && input.files.length > 0) {
            count.textContent = input.files.length;
            preview.classList.remove('hidden');

            Array.from(input.files).forEach((file, i) => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const div = document.createElement('div');
                    div.className = 'relative group';
                    div.innerHTML = `
                        <img src="${e.target.result}" alt="${file.name}"
                             class="w-full h-24 object-cover rounded-lg border border-gray-200 shadow-sm">
                        <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-40 rounded-lg transition flex items-end justify-center">
                            <span class="text-white text-xs font-medium pb-1 opacity-0 group-hover:opacity-100 transition truncate px-1">${file.name}</span>
                        </div>
                        <span class="absolute top-1 left-1 bg-indigo-600 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center font-bold">${i + 1}</span>
                    `;
                    thumbs.appendChild(div);
                };
                reader.readAsDataURL(file);
            });
        } else {
            preview.classList.add('hidden');
        }
    }

    function clearBatchFiles() {
        const input = document.getElementById('bubble_sheets');
        input.value = '';
        document.getElementById('batch-preview').classList.add('hidden');
        document.getElementById('batch-thumbs').innerHTML = '';
    }

    // ===== Drag and Drop for Single =====
    const singleDrop = document.getElementById('single-drop-zone');
    if (singleDrop) {
        ['dragenter', 'dragover'].forEach(e => {
            singleDrop.addEventListener(e, (ev) => { ev.preventDefault(); singleDrop.classList.add('border-indigo-400', 'bg-indigo-50'); });
        });
        ['dragleave', 'drop'].forEach(e => {
            singleDrop.addEventListener(e, (ev) => { ev.preventDefault(); singleDrop.classList.remove('border-indigo-400', 'bg-indigo-50'); });
        });
        singleDrop.addEventListener('drop', (e) => {
            const input = document.getElementById('bubble_sheet');
            input.files = e.dataTransfer.files;
            previewSingleImage(input);
        });
    }

    // ===== Drag and Drop for Batch =====
    const batchDrop = document.getElementById('batch-drop-zone');
    if (batchDrop) {
        ['dragenter', 'dragover'].forEach(e => {
            batchDrop.addEventListener(e, (ev) => { ev.preventDefault(); batchDrop.classList.add('border-indigo-400', 'bg-indigo-50'); });
        });
        ['dragleave', 'drop'].forEach(e => {
            batchDrop.addEventListener(e, (ev) => { ev.preventDefault(); batchDrop.classList.remove('border-indigo-400', 'bg-indigo-50'); });
        });
        batchDrop.addEventListener('drop', (e) => {
            const input = document.getElementById('bubble_sheets');
            input.files = e.dataTransfer.files;
            previewBatchImages(input);
        });
    }
</script>
@endpush
@endsection
