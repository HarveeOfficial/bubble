<?php

namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function index()
    {
        $students = Student::orderBy('name')->paginate(20);
        return view('students.index', compact('students'));
    }

    public function importForm()
    {
        return view('students.import');
    }

    public function import(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        $file = $request->file('csv_file');

        // Read raw content and convert encoding if needed
        $raw = file_get_contents($file->getRealPath());
        $encoding = mb_detect_encoding($raw, ['UTF-8', 'Windows-1252', 'ISO-8859-1', 'ASCII'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $raw = mb_convert_encoding($raw, 'UTF-8', $encoding);
        }
        // Remove UTF-8 BOM if present
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

        // Write cleaned content to a temp file for fgetcsv
        $tmpPath = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($tmpPath, $raw);
        $handle = fopen($tmpPath, 'r');

        if (!$handle) {
            @unlink($tmpPath);
            return back()->with('error', 'Could not read the CSV file.');
        }

        // Read header row
        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return back()->with('error', 'CSV file is empty.');
        }

        // Normalize headers (trim + lowercase)
        $header = array_map(fn($h) => strtolower(trim($h)), $header);

        $idCol = array_search('id', $header);
        $nameCol = array_search('name', $header);
        $sectionCol = array_search('section', $header);

        if ($idCol === false || $nameCol === false) {
            fclose($handle);
            return back()->with('error', 'CSV must have "id" and "name" columns. Found: ' . implode(', ', $header));
        }

        $imported = 0;
        $updated = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $studentId = trim($row[$idCol] ?? '');
            $name = trim($row[$nameCol] ?? '');
            $section = $sectionCol !== false ? trim($row[$sectionCol] ?? '') : '';

            if (empty($studentId) || empty($name)) {
                $skipped++;
                continue;
            }

            $data = ['name' => $name];
            if (!empty($section)) {
                $data['section'] = $section;
            }

            $student = Student::where('student_id', $studentId)->first();

            if ($student) {
                $student->update($data);
                $updated++;
            } else {
                Student::create(array_merge(['student_id' => $studentId], $data));
                $imported++;
            }
        }

        fclose($handle);
        @unlink($tmpPath);

        $message = "CSV imported: {$imported} new, {$updated} updated.";
        if ($skipped > 0) {
            $message .= " {$skipped} row(s) skipped (missing id or name).";
        }

        return redirect()->route('students.index')->with('success', $message);
    }

    public function store(Request $request)
    {
        $request->validate([
            'student_id' => 'required|string|max:20',
            'name'       => 'required|string|max:255',
            'section'    => 'nullable|string|max:100',
        ]);

        $student = Student::where('student_id', $request->student_id)->first();

        if ($student) {
            $student->update(['name' => $request->name, 'section' => $request->section]);
            return redirect()->route('students.index')->with('success', 'Student updated.');
        }

        Student::create([
            'student_id' => $request->student_id,
            'name'       => $request->name,
            'section'    => $request->section,
        ]);

        return redirect()->route('students.index')->with('success', 'Student added.');
    }

    public function destroy(Student $student)
    {
        $student->delete();
        return redirect()->route('students.index')->with('success', 'Student removed.');
    }

    public function destroyAll()
    {
        Student::truncate();
        return redirect()->route('students.index')->with('success', 'All students removed.');
    }
}
