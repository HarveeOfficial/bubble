<?php

use App\Http\Controllers\AnswerKeyController;
use App\Http\Controllers\BubbleSheetController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\StudentController;
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
Route::get('/exams/answer-key/{answerKey}', [ExamController::class, 'participants'])->name('exams.participants');
Route::resource('exams', ExamController::class);

Route::get('/bubble-sheet/template', [BubbleSheetController::class, 'templateForm'])->name('bubble-sheet.template-form');
Route::get('/bubble-sheet/generate', [BubbleSheetController::class, 'generate'])->name('bubble-sheet.generate');

Route::get('/students', [StudentController::class, 'index'])->name('students.index');
Route::post('/students', [StudentController::class, 'store'])->name('students.store');
Route::get('/students/import', [StudentController::class, 'importForm'])->name('students.import');
Route::post('/students/import', [StudentController::class, 'import'])->name('students.import.store');
Route::delete('/students/destroy-all', [StudentController::class, 'destroyAll'])->name('students.destroy-all');
Route::delete('/students/{student}', [StudentController::class, 'destroy'])->name('students.destroy');
