<?php

use App\Http\Controllers\AnswerKeyController;
use App\Http\Controllers\BubbleSheetController;
use App\Http\Controllers\ExamController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', function () {
    $answerKeyCount = \App\Models\AnswerKey::count();
    $examCount = \App\Models\Exam::count();
    $processedCount = \App\Models\Exam::where('status', 'processed')->count();
    $averageScore = \App\Models\Exam::where('status', 'processed')->avg('percentage');
    $recentExams = \App\Models\Exam::with('answerKey')->latest()->limit(5)->get();
    return view('dashboard', compact('answerKeyCount', 'examCount', 'processedCount', 'averageScore', 'recentExams'));
})->name('dashboard');

Route::resource('answer-keys', AnswerKeyController::class);
Route::post('/exams/batch', [ExamController::class, 'storeBatch'])->name('exams.store-batch');
Route::post('/exams/{exam}/reprocess', [ExamController::class, 'reprocess'])->name('exams.reprocess');
Route::get('/exams/{exam}/debug-image', [ExamController::class, 'debugImage'])->name('exams.debug-image');
Route::resource('exams', ExamController::class);

Route::get('/bubble-sheet/template', [BubbleSheetController::class, 'templateForm'])->name('bubble-sheet.template-form');
Route::get('/bubble-sheet/generate', [BubbleSheetController::class, 'generate'])->name('bubble-sheet.generate');
