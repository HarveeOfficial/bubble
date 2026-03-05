<?php

namespace App\Http\Controllers;

use App\Models\AnswerKey;
use App\Models\Exam;
use App\Models\Student;
use App\Services\OMRService;
use Illuminate\Http\Request;

class ExamController extends Controller
{
    public function __construct(
        private OMRService $omrService
    ) {}

    public function index()
    {
        $answerKeys = AnswerKey::withCount('exams')
            ->with(['exams' => function ($q) {
                $q->where('status', 'processed');
            }])
            ->latest()
            ->paginate(15);

        return view('exams.index', compact('answerKeys'));
    }

    /**
     * Show all students/exams that used a given answer key.
     */
    public function participants(AnswerKey $answerKey, Request $request)
    {
        // Get all distinct sections for students who took this exam
        $studentIds = Exam::where('answer_key_id', $answerKey->id)
            ->whereNotNull('student_id')
            ->pluck('student_id');
        $sections = Student::whereIn('student_id', $studentIds)
            ->whereNotNull('section')
            ->where('section', '!=', '')
            ->distinct()
            ->orderBy('section')
            ->pluck('section');

        $query = Exam::where('answer_key_id', $answerKey->id);

        // Filter by section if selected
        $selectedSection = $request->query('section');
        if ($selectedSection) {
            $sectionStudentIds = Student::where('section', $selectedSection)->pluck('student_id');
            $query->whereIn('student_id', $sectionStudentIds);
        }

        $exams = $query->latest()->paginate(20)->appends(['section' => $selectedSection]);

        return view('exams.participants', compact('answerKey', 'exams', 'sections', 'selectedSection'));
    }

    public function create()
    {
        $answerKeys = AnswerKey::all();
        return view('exams.create', compact('answerKeys'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'answer_key_id' => 'required|exists:answer_keys,id',
            'student_name' => 'nullable|string|max:255',
            'student_id' => 'nullable|string|max:255',
            'bubble_sheet' => 'required|image|mimes:jpeg,png,jpg,gif,bmp,webp|max:10240',
        ]);

        // Store the uploaded image
        $path = $request->file('bubble_sheet')->store('bubble-sheets', 'public');

        $exam = Exam::create([
            'answer_key_id' => $validated['answer_key_id'],
            'student_name' => $validated['student_name'],
            'student_id' => $validated['student_id'],
            'image_path' => $path,
            'status' => 'pending',
        ]);

        // Process the bubble sheet using OMR (auto-detects student ID from bubble grid)
        $exam = $this->omrService->processSheet($exam);

        if ($exam->status === 'processed') {
            return redirect()->route('exams.show', $exam)
                ->with('success', 'Bubble sheet processed successfully! Score: ' . $exam->score . '/' . $exam->total_items);
        }

        return redirect()->route('exams.show', $exam)
            ->with('warning', 'Bubble sheet uploaded but processing encountered issues. You can manually enter answers.');
    }

    /**
     * Process multiple bubble sheet images at once.
     * Student IDs are auto-detected from the bubble grid on each sheet.
     */
    public function storeBatch(Request $request)
    {
        $validated = $request->validate([
            'answer_key_id' => 'required|exists:answer_keys,id',
            'bubble_sheets' => 'required|array|min:1',
            'bubble_sheets.*' => 'required|image|mimes:jpeg,png,jpg,gif,bmp,webp|max:10240',
        ]);

        $results = [
            'processed' => 0,
            'failed' => 0,
            'total' => count($request->file('bubble_sheets')),
        ];

        foreach ($request->file('bubble_sheets') as $file) {
            $path = $file->store('bubble-sheets', 'public');

            $exam = Exam::create([
                'answer_key_id' => $validated['answer_key_id'],
                'image_path' => $path,
                'status' => 'pending',
            ]);

            // Process using OMR and auto-detect student ID from the bubble grid
            $exam = $this->omrService->processSheet($exam);

            if ($exam->status === 'processed') {
                $results['processed']++;
            } else {
                $results['failed']++;
            }
        }

        $message = "Batch complete: {$results['processed']}/{$results['total']} sheets processed successfully.";
        if ($results['failed'] > 0) {
            $message .= " {$results['failed']} sheet(s) failed.";
        }

        return redirect()->route('exams.index')
            ->with($results['failed'] > 0 ? 'warning' : 'success', $message);
    }

    public function show(Exam $exam)
    {
        $exam->load(['answerKey', 'results']);
        return view('exams.show', compact('exam'));
    }

    public function edit(Exam $exam)
    {
        $exam->load('answerKey');
        return view('exams.edit', compact('exam'));
    }

    public function update(Request $request, Exam $exam)
    {
        $validated = $request->validate([
            'student_name' => 'nullable|string|max:255',
            'student_id' => 'nullable|string|max:255',
            'manual_answers' => 'nullable|array',
            'manual_answers.*' => 'nullable|string|in:A,B,C,D,E',
        ]);

        $exam->update([
            'student_name' => $validated['student_name'],
            'student_id' => $validated['student_id'],
        ]);

        // If manual answers provided, re-grade
        if (!empty($validated['manual_answers'])) {
            // Delete old results
            $exam->results()->delete();

            $gradeResult = $this->omrService->gradeExam(
                $validated['manual_answers'],
                $exam->answerKey->answers
            );

            $exam->update([
                'detected_answers' => $validated['manual_answers'],
                'score' => $gradeResult['score'],
                'total_items' => $exam->answerKey->total_items,
                'percentage' => $gradeResult['percentage'],
                'status' => 'processed',
            ]);

            foreach ($gradeResult['details'] as $detail) {
                $exam->results()->create($detail);
            }
        }

        return redirect()->route('exams.show', $exam)
            ->with('success', 'Exam updated successfully.');
    }

    public function destroy(Exam $exam)
    {
        // Delete the uploaded image
        if ($exam->image_path) {
            \Storage::disk('public')->delete($exam->image_path);
        }

        $exam->delete();
        return redirect()->route('exams.index')
            ->with('success', 'Exam deleted successfully.');
    }

    /**
     * Re-process an exam's bubble sheet with the latest detection algorithm.
     */
    public function reprocess(Exam $exam)
    {
        // Delete old results
        $exam->results()->delete();
        $exam->update(['status' => 'pending']);

        $exam = $this->omrService->processSheet($exam);

        if ($exam->status === 'processed') {
            return redirect()->route('exams.show', $exam)
                ->with('success', 'Re-processed successfully! Score: ' . $exam->score . '/' . $exam->total_items);
        }

        return redirect()->route('exams.show', $exam)
            ->with('warning', 'Re-processing encountered issues.');
    }

    /**
     * Generate and serve a debug overlay image showing detection sampling regions.
     */
    public function debugImage(Exam $exam)
    {
        $exam->load('answerKey');
        $imagePath = storage_path('app/public/' . $exam->image_path);

        if (!file_exists($imagePath)) {
            abort(404, 'Image not found');
        }

        $columns = $exam->answerKey
            ? $this->omrService->inferColumnCount($exam->answerKey->total_items)
            : 2;

        // Delete old debug image to force regeneration
        $debugPath = preg_replace('/\.(jpe?g|png|gif|bmp|webp)$/i', '_debug.png', $imagePath);
        if (file_exists($debugPath)) {
            unlink($debugPath);
        }

        $debugPath = $this->omrService->generateDebugImage(
            $imagePath,
            $exam->answerKey->total_items ?? 30,
            $exam->answerKey->choices_per_item ?? 4,
            $columns,
            10
        );

        if (!$debugPath || !file_exists($debugPath)) {
            abort(500, 'Could not generate debug image');
        }

        return response()->file($debugPath, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
        ]);
    }
}
