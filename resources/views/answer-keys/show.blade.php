@extends('layouts.app')
@section('title', $answerKey->name . ' - Answer Key')

@section('content')
<div class="mb-6">
    <a href="{{ route('answer-keys.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Back to Answer Keys</a>
    <div class="flex items-center justify-between mt-2">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">{{ $answerKey->name }}</h1>
            @if($answerKey->subject)
                <p class="text-gray-500 mt-1">{{ $answerKey->subject }}</p>
            @endif
        </div>
        <div class="flex space-x-3">
            <a href="{{ route('exams.create', ['answer_key_id' => $answerKey->id]) }}" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition shadow-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                </svg>
                Scan Sheet
            </a>
            <a href="{{ route('answer-keys.edit', $answerKey) }}" class="inline-flex items-center px-4 py-2 bg-white text-gray-700 text-sm font-medium rounded-lg border border-gray-300 hover:bg-gray-50 transition">
                Edit
            </a>
            <form action="{{ route('answer-keys.destroy', $answerKey) }}" method="POST" onsubmit="return confirm('Delete this answer key?')">
                @csrf @method('DELETE')
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-white text-red-600 text-sm font-medium rounded-lg border border-gray-300 hover:bg-red-50 transition">
                    Delete
                </button>
            </form>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    {{-- Answer Key Details --}}
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Details</h2>
            <dl class="space-y-3">
                <div>
                    <dt class="text-xs font-medium text-gray-500 uppercase">Total Items</dt>
                    <dd class="text-sm text-gray-900 font-medium">{{ $answerKey->total_items }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-gray-500 uppercase">Choices per Item</dt>
                    <dd class="text-sm text-gray-900 font-medium">{{ $answerKey->choices_per_item }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-gray-500 uppercase">Created</dt>
                    <dd class="text-sm text-gray-900">{{ $answerKey->created_at->format('M d, Y h:i A') }}</dd>
                </div>
            </dl>
        </div>

        {{-- Answer Grid --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mt-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Correct Answers</h2>
            <div class="grid grid-cols-2 gap-2">
                @foreach($answerKey->answers as $num => $answer)
                    <div class="flex items-center space-x-2 py-1">
                        <span class="text-xs text-gray-400 w-6 text-right">{{ $num }}.</span>
                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold">
                            {{ $answer }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Associated Exams --}}
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-gray-900">Exams Using This Key</h2>
                <span class="text-sm text-gray-500">{{ $answerKey->exams->count() }} exam(s)</span>
            </div>
            @if($answerKey->exams->count())
                <div class="divide-y divide-gray-100">
                    @foreach($answerKey->exams as $exam)
                        <a href="{{ route('exams.show', $exam) }}" class="flex items-center justify-between px-6 py-4 hover:bg-gray-50 transition">
                            <div>
                                <p class="text-sm font-medium text-gray-900">{{ $exam->student_name ?? 'Unknown Student' }}</p>
                                <p class="text-xs text-gray-500">{{ $exam->student_id ? 'ID: ' . $exam->student_id . ' · ' : '' }}{{ $exam->created_at->diffForHumans() }}</p>
                            </div>
                            <div>
                                @if($exam->status === 'processed')
                                    <span class="text-sm font-semibold {{ $exam->percentage >= 75 ? 'text-green-600' : ($exam->percentage >= 50 ? 'text-amber-600' : 'text-red-600') }}">
                                        {{ $exam->score }}/{{ $exam->total_items }}
                                    </span>
                                @elseif($exam->status === 'pending')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">Pending</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">Failed</span>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="px-6 py-12 text-center text-gray-400">
                    <p>No exams have been scanned with this answer key yet.</p>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
