@extends('layouts.app')
@section('title', 'Exam Result - ' . ($exam->student_name ?? 'Unknown'))

@section('content')
<div class="mb-6">
    <a href="{{ route('exams.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Back to Exams</a>
    <div class="flex items-center justify-between mt-2">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">{{ $exam->student_name ?? 'Unknown Student' }}</h1>
            <p class="text-gray-500 mt-1">
                {{ $exam->answerKey->name ?? 'N/A' }}
                @if($exam->student_id) &middot; ID: {{ $exam->student_id }} @endif
                &middot; {{ $exam->created_at->format('M d, Y h:i A') }}
            </p>
        </div>
        <div class="flex space-x-3">
            <form action="{{ route('exams.reprocess', $exam) }}" method="POST" onsubmit="return confirm('Re-process this bubble sheet with updated detection?')">
                @csrf
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition">
                    &#x21bb; Re-process
                </button>
            </form>
            <a href="{{ route('exams.edit', $exam) }}" class="inline-flex items-center px-4 py-2 bg-white text-gray-700 text-sm font-medium rounded-lg border border-gray-300 hover:bg-gray-50 transition">
                Edit / Manual Grade
            </a>
            <form action="{{ route('exams.destroy', $exam) }}" method="POST" onsubmit="return confirm('Delete this exam?')">
                @csrf @method('DELETE')
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-white text-red-600 text-sm font-medium rounded-lg border border-gray-300 hover:bg-red-50 transition">
                    Delete
                </button>
            </form>
        </div>
    </div>
</div>

{{-- Score Banner --}}
@if($exam->status === 'processed')
    <div class="rounded-xl p-6 mb-6 {{ $exam->percentage >= 75 ? 'bg-green-50 border border-green-200' : ($exam->percentage >= 50 ? 'bg-amber-50 border border-amber-200' : 'bg-red-50 border border-red-200') }}">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium {{ $exam->percentage >= 75 ? 'text-green-800' : ($exam->percentage >= 50 ? 'text-amber-800' : 'text-red-800') }}">Final Score</p>
                <p class="text-4xl font-bold {{ $exam->percentage >= 75 ? 'text-green-900' : ($exam->percentage >= 50 ? 'text-amber-900' : 'text-red-900') }} mt-1">
                    {{ $exam->score }} / {{ $exam->total_items }}
                </p>
            </div>
            <div class="text-right">
                <p class="text-5xl font-bold {{ $exam->percentage >= 75 ? 'text-green-600' : ($exam->percentage >= 50 ? 'text-amber-600' : 'text-red-600') }}">
                    {{ number_format($exam->percentage, 1) }}%
                </p>
                <p class="text-sm mt-1 {{ $exam->percentage >= 75 ? 'text-green-700' : ($exam->percentage >= 50 ? 'text-amber-700' : 'text-red-700') }}">
                    @if($exam->percentage >= 90) Excellent!
                    @elseif($exam->percentage >= 75) Good
                    @elseif($exam->percentage >= 50) Needs Improvement
                    @else Below Passing
                    @endif
                </p>
            </div>
        </div>
    </div>
@elseif($exam->status === 'pending')
    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 mb-6">
        <p class="text-yellow-800 font-medium">This exam is pending processing.</p>
    </div>
@else
    <div class="bg-red-50 border border-red-200 rounded-xl p-6 mb-6">
        <p class="text-red-800 font-medium">Processing failed. You can manually enter answers by clicking "Edit / Manual Grade".</p>
    </div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    {{-- Uploaded Image --}}
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
            <h2 class="text-sm font-semibold text-gray-900 mb-3">Uploaded Sheet</h2>
            @if($exam->image_path)
                <img src="{{ asset('storage/' . $exam->image_path) }}" alt="Bubble Sheet" class="w-full rounded-lg border border-gray-200">
            @else
                <p class="text-gray-400 text-sm text-center py-8">No image available</p>
            @endif
        </div>

        {{-- Debug overlay --}}
        @if($exam->image_path)
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mt-4">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold text-gray-900">Detection Debug</h2>
                <button onclick="document.getElementById('debug-img').src='{{ route('exams.debug-image', $exam) }}?t='+Date.now(); document.getElementById('debug-panel').style.display='block';"
                        class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                    Generate
                </button>
            </div>
            <div id="debug-panel" style="display:none">
                <img id="debug-img" src="" alt="Debug overlay" class="w-full rounded-lg border border-gray-200">
                <p class="text-xs text-gray-400 mt-2">
                    <span class="text-red-500 font-bold">Red</span> = answer bubble regions &middot;
                    <span class="text-green-500 font-bold">Green</span> = ID digit regions &middot;
                    <span class="text-blue-500 font-bold">Blue</span> = content bounds
                </p>
            </div>
        </div>
        @endif
    </div>

    {{-- Detailed Results --}}
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Answer Details</h2>
            </div>

            @if($exam->results && $exam->results->count())
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">#</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Correct Answer</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student Answer</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Result</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            @foreach($exam->results->sortBy('question_number') as $result)
                                <tr class="{{ $result->is_correct ? '' : 'bg-red-50/50' }}">
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-500">{{ $result->question_number }}</td>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 text-sm font-bold">
                                            {{ $result->correct_answer }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        @if($result->student_answer)
                                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full text-sm font-bold
                                                {{ $result->is_correct ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                                {{ $result->student_answer }}
                                            </span>
                                        @else
                                            <span class="text-gray-400 text-sm italic">No answer</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        @if($result->is_correct)
                                            <span class="inline-flex items-center text-green-600">
                                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                                </svg>
                                                <span class="ml-1 text-sm font-medium">Correct</span>
                                            </span>
                                        @else
                                            <span class="inline-flex items-center text-red-600">
                                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                                                </svg>
                                                <span class="ml-1 text-sm font-medium">Wrong</span>
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="px-6 py-12 text-center text-gray-400">
                    <p>No detailed results available.</p>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
