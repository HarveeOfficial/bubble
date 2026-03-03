<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'OMR Sheet Checker')</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    {{-- Navigation --}}
    <nav class="bg-white border-b border-gray-200 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center space-x-8">
                    <a href="{{ route('dashboard') }}" class="text-xl font-bold text-indigo-600">
                        &#9899; OMR Sheet Checker
                    </a>
                    <div class="hidden sm:flex space-x-4">
                        <a href="{{ route('dashboard') }}"
                           class="px-3 py-2 rounded-md text-sm font-medium {{ request()->routeIs('dashboard') ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                            Dashboard
                        </a>
                        <a href="{{ route('answer-keys.index') }}"
                           class="px-3 py-2 rounded-md text-sm font-medium {{ request()->routeIs('answer-keys.*') ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                            Answer Keys
                        </a>
                        <a href="{{ route('exams.index') }}"
                           class="px-3 py-2 rounded-md text-sm font-medium {{ request()->routeIs('exams.*') ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                            Exams
                        </a>
                        <a href="{{ route('bubble-sheet.template-form') }}"
                           class="px-3 py-2 rounded-md text-sm font-medium {{ request()->routeIs('bubble-sheet.*') ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                            Bubble Sheet
                        </a>
                        <a href="{{ route('students.index') }}"
                           class="px-3 py-2 rounded-md text-sm font-medium {{ request()->routeIs('students.*') ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                            Students
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    {{-- Flash Messages --}}
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
        @if(session('success'))
            <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg mb-4 flex items-center justify-between" id="flash-success">
                <span>{{ session('success') }}</span>
                <button onclick="document.getElementById('flash-success').remove()" class="text-green-600 hover:text-green-800">&times;</button>
            </div>
        @endif
        @if(session('warning'))
            <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-lg mb-4 flex items-center justify-between" id="flash-warning">
                <span>{{ session('warning') }}</span>
                <button onclick="document.getElementById('flash-warning').remove()" class="text-yellow-600 hover:text-yellow-800">&times;</button>
            </div>
        @endif
        @if(session('error'))
            <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-4 flex items-center justify-between" id="flash-error">
                <span>{{ session('error') }}</span>
                <button onclick="document.getElementById('flash-error').remove()" class="text-red-600 hover:text-red-800">&times;</button>
            </div>
        @endif
    </div>

    {{-- Page Content --}}
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        @yield('content')
    </main>

    @stack('scripts')
</body>
</html>
