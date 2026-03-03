@extends('layouts.app')
@section('title', 'Exams - OMR Sheet Checker')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Exams</h1>
        <p class="text-gray-500 mt-1">View exams and the students who took them.</p>
    </div>
    <a href="{{ route('exams.create') }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition shadow-sm">
        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
        </svg>
        Scan New Sheet
    </a>
</div>

@if($answerKeys->count())
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        @foreach($answerKeys as $ak)
            @php
                $processed = $ak->exams->count();
                $avg = $processed > 0 ? $ak->exams->avg('percentage') : null;
                $highest = $processed > 0 ? $ak->exams->max('percentage') : null;
                $lowest = $processed > 0 ? $ak->exams->min('percentage') : null;
            @endphp
            <a href="{{ route('exams.participants', $ak) }}" class="block bg-white rounded-xl shadow-sm border border-gray-200 hover:shadow-md hover:border-indigo-200 transition group">
                <div class="p-5">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900 group-hover:text-indigo-600 transition">{{ $ak->name }}</h3>
                            @if($ak->subject)
                                <p class="text-xs text-gray-400 mt-0.5">{{ $ak->subject }}</p>
                            @endif
                        </div>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-50 text-indigo-700">
                            {{ $ak->exams_count }} {{ Str::plural('student', $ak->exams_count) }}
                        </span>
                    </div>

                    <div class="mt-4 grid grid-cols-3 gap-3 text-center">
                        <div>
                            <p class="text-xs text-gray-400">Items</p>
                            <p class="text-sm font-semibold text-gray-900">{{ $ak->total_items }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-400">Choices</p>
                            <p class="text-sm font-semibold text-gray-900">{{ $ak->choices_per_item }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-400">Average</p>
                            <p class="text-sm font-semibold {{ $avg !== null ? ($avg >= 75 ? 'text-green-600' : ($avg >= 50 ? 'text-amber-600' : 'text-red-600')) : 'text-gray-400' }}">
                                {{ $avg !== null ? number_format($avg, 1) . '%' : '—' }}
                            </p>
                        </div>
                    </div>

                    @if($processed > 0)
                        <div class="mt-3 flex items-center space-x-2">
                            <div class="flex-1 bg-gray-200 rounded-full h-1.5">
                                <div class="h-1.5 rounded-full {{ $avg >= 75 ? 'bg-green-500' : ($avg >= 50 ? 'bg-amber-500' : 'bg-red-500') }}"
                                     style="width: {{ min($avg, 100) }}%"></div>
                            </div>
                        </div>
                        <div class="mt-2 flex justify-between text-xs text-gray-400">
                            <span>Low: {{ number_format($lowest, 1) }}%</span>
                            <span>High: {{ number_format($highest, 1) }}%</span>
                        </div>
                    @endif
                </div>
                <div class="border-t border-gray-100 px-5 py-3">
                    <p class="text-xs text-gray-400">Created {{ $ak->created_at->format('M d, Y') }}</p>
                </div>
            </a>
        @endforeach
    </div>

    <div class="mt-6">
        {{ $answerKeys->links() }}
    </div>
@else
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 px-6 py-16 text-center">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        <h3 class="text-lg font-medium text-gray-900 mb-2">No exams yet</h3>
        <p class="text-gray-500 mb-6">Create an answer key and upload bubble sheets to start grading.</p>
        <a href="{{ route('exams.create') }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition">
            Scan Bubble Sheet
        </a>
    </div>
@endif
@endsection
