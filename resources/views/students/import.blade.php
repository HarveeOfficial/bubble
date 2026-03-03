@extends('layouts.app')
@section('title', 'Import Students - OMR Sheet Checker')

@section('content')
<div class="mb-6">
    <a href="{{ route('students.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Back to Students</a>
    <h1 class="text-2xl font-bold text-gray-900 mt-2">Import Students from CSV</h1>
    <p class="text-gray-500 mt-1">Upload a CSV file with student IDs and names. Existing entries will be updated.</p>
</div>

<div class="max-w-xl">
    <form action="{{ route('students.import.store') }}" method="POST" enctype="multipart/form-data" class="space-y-6">
        @csrf

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">CSV File</h2>

            <div>
                <label for="csv_file" class="block text-sm font-medium text-gray-700 mb-2">Select CSV File <span class="text-red-500">*</span></label>

                <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-xl hover:border-indigo-400 transition" id="csv-drop-zone">
                    <div class="space-y-2 text-center">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <div class="flex text-sm text-gray-600 justify-center">
                            <label for="csv_file" class="relative cursor-pointer rounded-md font-medium text-indigo-600 hover:text-indigo-500">
                                <span>Choose a file</span>
                                <input id="csv_file" name="csv_file" type="file" accept=".csv,.txt" required class="sr-only" onchange="showFileName(this)">
                            </label>
                            <p class="pl-1">or drag and drop</p>
                        </div>
                        <p class="text-xs text-gray-500">CSV or TXT, max 2MB</p>
                    </div>
                </div>

                <p id="csv-file-name" class="text-sm text-indigo-600 mt-2 hidden"></p>

                @error('csv_file') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="mt-5 p-4 bg-gray-50 rounded-lg">
                <h4 class="text-sm font-medium text-gray-700 mb-2">CSV Format</h4>
                <p class="text-xs text-gray-500 mb-2">The file must have a header row with <strong>id</strong> and <strong>name</strong> columns:</p>
                <div class="bg-white border border-gray-200 rounded-md p-3 font-mono text-xs text-gray-700">
                    <div>id,name</div>
                    <div>1234567,Juan Dela Cruz</div>
                    <div>2345678,Maria Santos</div>
                    <div>3456789,Jose Rizal</div>
                </div>
            </div>
        </div>

        <div class="flex justify-end space-x-3">
            <a href="{{ route('students.index') }}" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">
                Cancel
            </a>
            <button type="submit" class="px-6 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition shadow-sm">
                Import CSV
            </button>
        </div>
    </form>
</div>

@push('scripts')
<script>
function showFileName(input) {
    const label = document.getElementById('csv-file-name');
    if (input.files.length > 0) {
        label.textContent = input.files[0].name;
        label.classList.remove('hidden');
    }
}
</script>
@endpush
@endsection
