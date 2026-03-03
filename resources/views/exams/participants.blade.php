@extends('layouts.app')
@section('title', $answerKey->name . ' - Exam Results')

@section('content')
<div class="mb-6">
    <a href="{{ route('exams.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Back to Exams</a>
    <div class="flex items-center justify-between mt-2">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">{{ $answerKey->name }}</h1>
            <p class="text-gray-500 mt-1">
                @if($answerKey->subject) {{ $answerKey->subject }} &middot; @endif
                {{ $answerKey->total_items }} items &middot; {{ $answerKey->choices_per_item }} choices
            </p>
        </div>
        <a href="{{ route('exams.create', ['answer_key_id' => $answerKey->id]) }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition shadow-sm">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
            </svg>
            Scan Sheet
        </a>
    </div>
</div>

{{-- Stats Summary --}}
@php
    $processedExams = $exams->where('status', 'processed');
    $avg = $processedExams->count() > 0 ? $processedExams->avg('percentage') : null;
    $highest = $processedExams->count() > 0 ? $processedExams->max('percentage') : null;
    $lowest = $processedExams->count() > 0 ? $processedExams->min('percentage') : null;
    $passed = $processedExams->where('percentage', '>=', 75)->count();
@endphp
@if($processedExams->count() > 0)
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
            <p class="text-xs text-gray-400 uppercase">Students</p>
            <p class="text-xl font-bold text-gray-900 mt-1">{{ $exams->total() }}</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
            <p class="text-xs text-gray-400 uppercase">Average</p>
            <p class="text-xl font-bold {{ $avg >= 75 ? 'text-green-600' : ($avg >= 50 ? 'text-amber-600' : 'text-red-600') }} mt-1">{{ number_format($avg, 1) }}%</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
            <p class="text-xs text-gray-400 uppercase">Highest</p>
            <p class="text-xl font-bold text-green-600 mt-1">{{ number_format($highest, 1) }}%</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
            <p class="text-xs text-gray-400 uppercase">Lowest</p>
            <p class="text-xl font-bold text-red-600 mt-1">{{ number_format($lowest, 1) }}%</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
            <p class="text-xs text-gray-400 uppercase">Passed (≥75%)</p>
            <p class="text-xl font-bold text-indigo-600 mt-1">{{ $passed }}/{{ $processedExams->count() }}</p>
        </div>
    </div>
@endif

{{-- Student Results Table --}}
@if($exams->count())
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Score</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Percentage</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-100">
                @foreach($exams as $exam)
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <a href="{{ route('exams.show', $exam) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">
                                {{ $exam->student_name ?? 'Unknown' }}
                            </a>
                            @if($exam->student_id)
                                <p class="text-xs text-gray-400">ID: {{ $exam->student_id }}</p>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            {{ $exam->status === 'processed' ? $exam->score . '/' . $exam->total_items : '—' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            @if($exam->status === 'processed')
                                <div class="flex items-center space-x-2">
                                    <div class="w-16 bg-gray-200 rounded-full h-2">
                                        <div class="h-2 rounded-full {{ $exam->percentage >= 75 ? 'bg-green-500' : ($exam->percentage >= 50 ? 'bg-amber-500' : 'bg-red-500') }}"
                                             style="width: {{ min($exam->percentage, 100) }}%"></div>
                                    </div>
                                    <span class="text-sm font-medium {{ $exam->percentage >= 75 ? 'text-green-600' : ($exam->percentage >= 50 ? 'text-amber-600' : 'text-red-600') }}">
                                        {{ number_format($exam->percentage, 1) }}%
                                    </span>
                                </div>
                            @else
                                <span class="text-sm text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            @if($exam->status === 'processed')
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Processed</span>
                            @elseif($exam->status === 'pending')
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">Pending</span>
                            @else
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">Failed</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $exam->created_at->format('M d, Y') }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            <a href="{{ route('exams.show', $exam) }}" class="text-gray-400 hover:text-gray-600 mr-3">View</a>
                            <form action="{{ route('exams.destroy', $exam) }}" method="POST" class="inline" onsubmit="return confirm('Delete this exam result?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-red-400 hover:text-red-600">Delete</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-4">
        {{ $exams->links() }}
    </div>
@else
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 px-6 py-16 text-center">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
        </svg>
        <h3 class="text-lg font-medium text-gray-900 mb-2">No students have taken this exam yet</h3>
        <p class="text-gray-500 mb-6">Upload scanned bubble sheets to start grading.</p>
        <a href="{{ route('exams.create', ['answer_key_id' => $answerKey->id]) }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition">
            Scan Bubble Sheet
        </a>
    </div>
@endif
@endsection
