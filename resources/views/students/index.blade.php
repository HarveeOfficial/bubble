@extends('layouts.app')
@section('title', 'Students - OMR Sheet Checker')

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Students</h1>
        <p class="text-gray-500 mt-1">Student roster imported from CSV. Used to auto-fill names when processing sheets.</p>
    </div>
    <div class="flex space-x-3">
        @if($students->total() > 0)
            <form action="{{ route('students.destroy-all') }}" method="POST" onsubmit="return confirm('Remove all students?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="px-4 py-2 text-sm font-medium text-red-700 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100 transition">
                    Clear All
                </button>
            </form>
        @endif
        <a href="{{ route('students.import') }}" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition shadow-sm">
            Import CSV
        </a>
    </div>
</div>

{{-- Manual Add Form --}}
<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5 mb-6">
    <h2 class="text-sm font-semibold text-gray-700 mb-3">Add Student</h2>
    <form action="{{ route('students.store') }}" method="POST" class="flex items-end gap-3">
        @csrf
        <div class="flex-shrink-0">
            <label for="student_id" class="block text-xs font-medium text-gray-500 mb-1">Student ID</label>
            <input type="text" name="student_id" id="student_id" value="{{ old('student_id') }}" required
                   class="w-40 px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                   placeholder="e.g. 1234567">
        </div>
        <div class="flex-1">
            <label for="name" class="block text-xs font-medium text-gray-500 mb-1">Name</label>
            <input type="text" name="name" id="name" value="{{ old('name') }}" required
                   class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                   placeholder="e.g. Juan Dela Cruz">
        </div>
        <div class="flex-shrink-0">
            <label for="section" class="block text-xs font-medium text-gray-500 mb-1">Section</label>
            <input type="text" name="section" id="section" value="{{ old('section') }}"
                   class="w-40 px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                   placeholder="e.g. BSIT-3A">
        </div>
        <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition shadow-sm">
            Add
        </button>
    </form>
    @if($errors->any())
        <div class="mt-2">
            @foreach($errors->all() as $error)
                <p class="text-red-500 text-xs">{{ $error }}</p>
            @endforeach
        </div>
    @endif
</div>

@if($students->isEmpty())
    <div class="bg-white border border-gray-200 rounded-xl p-12 text-center">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
        </svg>
        <h3 class="text-lg font-medium text-gray-500 mb-2">No students imported yet</h3>
        <p class="text-gray-400 mb-4">Import a CSV file with <code class="bg-gray-100 px-1 rounded">id</code>, <code class="bg-gray-100 px-1 rounded">name</code>, and <code class="bg-gray-100 px-1 rounded">section</code> columns.</p>
        <a href="{{ route('students.import') }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition">
            Import CSV
        </a>
    </div>
@else
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Section</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @foreach($students as $student)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-3 text-sm font-mono text-gray-900">{{ $student->student_id }}</td>
                        <td class="px-6 py-3 text-sm text-gray-700">{{ $student->name }}</td>
                        <td class="px-6 py-3 text-sm text-gray-500">{{ $student->section ?? '—' }}</td>
                        <td class="px-6 py-3 text-right">
                            <form action="{{ route('students.destroy', $student) }}" method="POST" class="inline" onsubmit="return confirm('Remove this student?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-500 hover:text-red-700 text-sm">Remove</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $students->links() }}
    </div>
@endif
@endsection
