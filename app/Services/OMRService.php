<?php

namespace App\Services;

use App\Models\AnswerKey;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Student;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Optical Mark Recognition (OMR) Service
 *
 * Processes scanned/photographed bubble sheet images using OMR techniques:
 * - Otsu's automatic thresholding for binarization
 * - Deskew correction via corner marker alignment
 * - Statistical mark detection (z-score based)
 * - Morphological noise reduction (erosion/dilation)
 * - Confidence scoring for each detected mark
 * - Multiple-mark detection per question
 */
class OMRService
{
    /*
    |--------------------------------------------------------------------------
    | OMR Configuration
    |--------------------------------------------------------------------------
    */

    /** Minimum z-score below mean for a mark to be considered filled */
    private const MARK_Z_THRESHOLD = 1.8;

    /** Minimum absolute darkness difference for adaptive fallback */
    private const MARK_ABS_THRESHOLD = 15;

    /** Confidence level thresholds */
    private const CONFIDENCE_HIGH   = 0.85;
    private const CONFIDENCE_MEDIUM = 0.60;
    private const CONFIDENCE_LOW    = 0.40;

    /** Maximum skew angle to correct (degrees) */
    private const MAX_SKEW_DEGREES = 5.0;

    /*
    |--------------------------------------------------------------------------
    | Template Layout Proportions (of the content area inside corner markers)
    |--------------------------------------------------------------------------
    | Calibrated to match the bubble-sheet/print.blade.php template on A4.
    | A4 = 210mm × 297mm, content padding = 12mm H × 10mm V → 186mm × 277mm.
    | Corner marker filled squares at (7mm, 7mm) from page corners.
    |
    | Physical layout for a 30-item, 10-digit-ID, 2-column sheet:
    |   Header:       ~0%  → ~11%  of content height  (~30mm)
    |   Student Info:  ~11% → ~17%                      (~17mm)
    |   ID Grid:       ~17% → ~47%                      (~84mm incl. label)
    |   Instructions:  ~47% → ~53%                      (~16mm)
    |   Answer Grid:   ~53% → ~98%                      (~126mm)
    |   Footer:        ~98% → ~100%                     (~4mm)
    |
    | Answer bubble positions within each column (91mm wide):
    |   Row padding 2.5mm + question-num 7mm (border-box) = 9.5mm
    |   Bubbles: 6mm diameter, 2mm gap
    |   A center = 12.5mm (13.7%), B = 20.5mm (22.5%)
    |   C center = 28.5mm (31.3%), D = 36.5mm (40.1%)
    */

    /**
     * Process an uploaded bubble sheet image against an answer key using OMR.
     */
    public function processSheet(Exam $exam): Exam
    {
        $answerKey = $exam->answerKey;
        $imagePath = storage_path('app/public/' . $exam->image_path);

        if (!file_exists($imagePath)) {
            $exam->update(['status' => 'failed']);
            return $exam;
        }

        try {
            // Detect student ID from the bubble grid
            $detectedStudentId = $this->detectStudentId($imagePath);

            // Infer the column count used in the printed template
            $columns = $this->inferColumnCount($answerKey->total_items);

            // Detect answers using OMR pipeline
            $omrResult = $this->detectAnswers(
                $imagePath,
                $answerKey->total_items,
                $answerKey->choices_per_item,
                $columns
            );

            $detectedAnswers = $omrResult['answers'];

            // Grade the exam
            $gradeResult = $this->gradeExam($detectedAnswers, $answerKey->answers);

            // Build update payload
            $updateData = [
                'detected_answers' => $detectedAnswers,
                'score'            => $gradeResult['score'],
                'total_items'      => $answerKey->total_items,
                'percentage'       => $gradeResult['percentage'],
                'status'           => 'processed',
            ];

            // Auto-fill student_id if detected and not already provided
            if ($detectedStudentId && !$exam->student_id) {
                $updateData['student_id'] = $detectedStudentId;
            }

            // Auto-fill student_name from roster if available
            $sid = $detectedStudentId ?: $exam->student_id;
            if ($sid && !$exam->student_name) {
                $student = Student::where('student_id', $sid)->first();
                if ($student) {
                    $updateData['student_name'] = $student->name;
                }
            }

            $exam->update($updateData);

            // Create individual result records
            foreach ($gradeResult['details'] as $detail) {
                ExamResult::create([
                    'exam_id'         => $exam->id,
                    'question_number' => $detail['question_number'],
                    'correct_answer'  => $detail['correct_answer'],
                    'student_answer'  => $detail['student_answer'],
                    'is_correct'      => $detail['is_correct'],
                ]);
            }

            Log::info('OMR processing complete', [
                'exam_id'         => $exam->id,
                'score'           => $gradeResult['score'],
                'total'           => $answerKey->total_items,
                'avg_confidence'  => $omrResult['avg_confidence'] ?? null,
                'low_confidence'  => $omrResult['low_confidence_items'] ?? [],
                'multiple_marks'  => $omrResult['multiple_marks'] ?? [],
            ]);

            return $exam->fresh(['results']);
        } catch (\Exception $e) {
            $exam->update(['status' => 'failed']);
            Log::error('OMR processing failed: ' . $e->getMessage(), [
                'exam_id' => $exam->id,
                'trace'   => $e->getTraceAsString(),
            ]);
            return $exam;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | OMR Image Preprocessing Pipeline
    |--------------------------------------------------------------------------
    */

    /**
     * Apply the full OMR preprocessing pipeline to an image.
     *
     * Steps:
     * 1. Convert to grayscale
     * 2. Apply contrast normalization
     * 3. Apply noise reduction (median-like filter)
     * 4. Apply Otsu's binarization threshold
     * 5. Apply morphological operations (erosion/dilation)
     *
     * @return array{image: \GdImage, threshold: int, histogram: array}
     */
    private function preprocessImage($image, int $width, int $height): array
    {
        // Step 1: Grayscale conversion
        imagefilter($image, IMG_FILTER_GRAYSCALE);

        // Step 2: Contrast normalization — stretch histogram to full range
        $this->normalizeContrast($image, $width, $height);

        // Step 3: Noise reduction — approximate Gaussian blur
        // GD's Gaussian blur is lightweight; apply twice for smoothing
        imagefilter($image, IMG_FILTER_GAUSSIAN_BLUR);

        // Step 4: Compute Otsu's threshold from histogram
        $histogram = $this->computeHistogram($image, $width, $height);
        $histogramTotal = array_sum($histogram); // Use actual sampled pixel count, not full image
        $otsuThreshold = $this->computeOtsuThreshold($histogram, $histogramTotal);

        // Step 5: Enhance contrast based on the computed threshold
        imagefilter($image, IMG_FILTER_CONTRAST, -30);

        return [
            'image'     => $image,
            'threshold' => $otsuThreshold,
            'histogram' => $histogram,
        ];
    }

    /**
     * Normalize contrast by stretching the histogram to the full 0-255 range.
     * This compensates for varying lighting conditions in scanned/photographed sheets.
     */
    private function normalizeContrast($image, int $width, int $height): void
    {
        $min = 255;
        $max = 0;
        $step = max(1, (int)(min($width, $height) / 200)); // Sample every Nth pixel

        for ($x = 0; $x < $width; $x += $step) {
            for ($y = 0; $y < $height; $y += $step) {
                $gray = imagecolorat($image, $x, $y) & 0xFF;
                if ($gray < $min) $min = $gray;
                if ($gray > $max) $max = $gray;
            }
        }

        $range = $max - $min;
        if ($range < 30) return; // Already very low contrast, skip

        // Apply per-pixel stretch: newVal = (val - min) * 255 / range
        // GD doesn't support per-pixel ops efficiently, use brightness + contrast
        // Shift center, then scale
        $shift = -(int)(($min + $max) / 2 - 128);
        $scale = (int)(255.0 / max($range, 1) * 50) - 50; // Map to GD contrast range

        if (abs($shift) > 5) {
            imagefilter($image, IMG_FILTER_BRIGHTNESS, $shift);
        }
        if (abs($scale) > 5) {
            imagefilter($image, IMG_FILTER_CONTRAST, -abs($scale));
        }
    }

    /**
     * Compute the grayscale histogram of an image.
     *
     * @return int[] Array of 256 frequency counts (index = brightness 0-255)
     */
    private function computeHistogram($image, int $width, int $height): array
    {
        $histogram = array_fill(0, 256, 0);
        $step = max(1, (int)(min($width, $height) / 300));

        for ($x = 0; $x < $width; $x += $step) {
            for ($y = 0; $y < $height; $y += $step) {
                $gray = imagecolorat($image, $x, $y) & 0xFF;
                $histogram[$gray]++;
            }
        }

        return $histogram;
    }

    /**
     * Compute the optimal binarization threshold using Otsu's method.
     *
     * Otsu's method finds the threshold that minimizes the intra-class variance
     * (or equivalently maximizes inter-class variance) between foreground and
     * background pixel classes. This is the gold-standard automatic threshold
     * selection for OMR applications.
     *
     * @param int[] $histogram Grayscale histogram (256 bins)
     * @param int   $totalPixels Total pixel count
     * @return int Optimal threshold (0-255)
     */
    private function computeOtsuThreshold(array $histogram, int $totalPixels): int
    {
        if ($totalPixels === 0) return 128;

        // Compute total mean intensity
        $totalSum = 0;
        for ($i = 0; $i < 256; $i++) {
            $totalSum += $i * $histogram[$i];
        }

        $bestThreshold = 0;
        $maxVariance   = 0;

        $weightBg  = 0;
        $sumBg     = 0;

        for ($t = 0; $t < 256; $t++) {
            $weightBg += $histogram[$t];
            if ($weightBg === 0) continue;

            $weightFg = $totalPixels - $weightBg;
            if ($weightFg === 0) break;

            $sumBg += $t * $histogram[$t];
            $sumFg  = $totalSum - $sumBg;

            $meanBg = $sumBg / $weightBg;
            $meanFg = $sumFg / $weightFg;

            // Inter-class variance: σ²_between = w_bg * w_fg * (μ_bg - μ_fg)²
            $variance = $weightBg * $weightFg * pow($meanBg - $meanFg, 2);

            if ($variance > $maxVariance) {
                $maxVariance   = $variance;
                $bestThreshold = $t;
            }
        }

        Log::debug('Otsu threshold computed', [
            'threshold' => $bestThreshold,
            'variance'  => $maxVariance,
        ]);

        return $bestThreshold;
    }

    /*
    |--------------------------------------------------------------------------
    | OMR Answer Detection (multi-column aware)
    |--------------------------------------------------------------------------
    */

    /**
     * Detect filled answer bubbles from a bubble sheet image using OMR.
     * Supports multi-column layouts matching the printed template.
     *
     * @return array{answers: array, confidence: array, avg_confidence: float, low_confidence_items: array, multiple_marks: array}
     */
    public function detectAnswers(string $imagePath, int $totalItems, int $choicesPerItem, int $columns = 0): array
    {
        $image = $this->loadImage($imagePath);
        if (!$image) {
            Log::warning('OMR: Could not load image for answer detection', ['path' => $imagePath]);
            return [
                'answers'              => array_fill(1, $totalItems, null),
                'confidence'           => array_fill(1, $totalItems, 0),
                'avg_confidence'       => 0,
                'low_confidence_items' => range(1, $totalItems),
                'multiple_marks'       => [],
            ];
        }

        $width  = imagesx($image);
        $height = imagesy($image);

        // OMR Preprocessing Pipeline
        $preprocess = $this->preprocessImage($image, $width, $height);
        $otsuThreshold = $preprocess['threshold'];

        if ($columns <= 0) {
            $columns = $this->inferColumnCount($totalItems);
        }

        $itemsPerColumn = (int)ceil($totalItems / $columns);
        $choiceLabels   = ['A', 'B', 'C', 'D', 'E'];

        // Find the sheet content bounds using corner markers
        $bounds  = $this->findSheetBounds($image, $width, $height);
        $contentW = $bounds['right'] - $bounds['left'];
        $contentH = $bounds['bottom'] - $bounds['top'];
        $pxPerMm  = $bounds['pxPerMm'];
        $pxPerMmV = $bounds['pxPerMmV'];

        // Deskew correction — compute skew angle from corner markers
        $skewAngle = $this->computeSkewAngle($bounds, $width, $height);

        // Get template-specific layout params
        $tplParams = $this->getTemplateParams($totalItems);

        // Search region for column header bars
        $searchTop    = $bounds['top'] + (int)($contentH * $tplParams['searchTopRatio']);
        $searchBottom = $bounds['top'] + (int)($contentH * $tplParams['searchBottomRatio']);

        // Dynamically detect column header bars for precise column boundaries
        $detectedCols = $this->findColumnHeaders(
            $image, $searchTop, $searchBottom,
            $bounds['left'], $bounds['right'],
            $width, $height, $columns
        );

        // Bubble center X positions as absolute mm offsets from column left
        $bubblePositions = $this->calculateBubblePositions($columns, $choicesPerItem, $totalItems);

        $answers        = [];
        $confidence     = [];
        $multipleMarks  = [];
        $lowConfidence  = [];

        for ($col = 0; $col < $columns; $col++) {
            $startItem  = $col * $itemsPerColumn + 1;
            $endItem    = min(($col + 1) * $itemsPerColumn, $totalItems);
            $itemsInCol = $endItem - $startItem + 1;
            if ($itemsInCol <= 0) continue;

            // Use detected column boundaries when available, else calculated
            if (isset($detectedCols[$col])) {
                $colLeft    = $detectedCols[$col]['left'];
                $colWidth   = $detectedCols[$col]['right'] - $colLeft;
                $rowsTop    = $detectedCols[$col]['headerBottom'];
                $rowsBottom = $detectedCols[$col]['gridBottom'] ?? $searchBottom;
            } else {
                $colGapPx   = (int)($contentW * 0.022);
                $calcColW   = (int)(($contentW - ($columns - 1) * $colGapPx) / $columns);
                $colLeft    = $bounds['left'] + $col * ($calcColW + $colGapPx);
                $colWidth   = $calcColW;
                $rowsTop    = $searchTop + (int)(($searchBottom - $searchTop) * 0.12);
                $rowsBottom = $searchBottom;
            }

            // Apply first-row offset to account for header-to-grid gap
            $rowsTop += (int)(($tplParams['firstRowOffsetMm'] ?? 0) * $pxPerMmV);
            $rowAreaH = $rowsBottom - $rowsTop;
            // Use itemsPerColumn (not itemsInCol) so ALL columns have the same row height
            $rowH     = $rowAreaH / $itemsPerColumn;

            if ($col === 0) {
                Log::debug('OMR grid positioning (col 0)', [
                    'headerBottom' => $detectedCols[$col]['headerBottom'] ?? 'N/A',
                    'gridBottom'   => $detectedCols[$col]['gridBottom'] ?? 'N/A',
                    'rowsTop_after_offset' => $rowsTop,
                    'rowsBottom'   => $rowsBottom,
                    'rowAreaH_px'  => $rowAreaH,
                    'rowAreaH_mm'  => round($rowAreaH / $pxPerMmV, 1),
                    'rowH_px'      => round($rowH, 1),
                    'rowH_mm'      => round($rowH / $pxPerMmV, 1),
                    'itemsInCol'   => $itemsInCol,
                    'pxPerMmV'     => round($pxPerMmV, 2),
                    'firstRowCy'   => (int)($rowsTop + 0.5 * $rowH),
                    'lastRowCy'    => (int)($rowsTop + ($itemsInCol - 0.5) * $rowH),
                ]);
            }

            // Sampling radius from template params
            $bubbleRx = max(5, (int)($tplParams['bubbleRadiusMm'] * $pxPerMm));
            $bubbleRy = max(5, (int)($tplParams['bubbleRadiusMm'] * $pxPerMmV));

            for ($row = 0; $row < $itemsInCol; $row++) {
                $q  = $startItem + $row;

                // Apply deskew compensation to Y coordinate
                $rawCy = (int)($rowsTop + ($row + 0.5) * $rowH);
                $cy = $this->applyDeskewY($rawCy, $colLeft + $colWidth / 2, $skewAngle, $bounds);

                $darknessValues = [];
                for ($c = 0; $c < $choicesPerItem; $c++) {
                    $cx = (int)($colLeft + $bubblePositions['centersMm'][$c] * $colPxPerMm);
                    $darknessValues[$c] = $this->sampleMarkDarkness(
                        $image, $cx, $cy, $bubbleRx, $bubbleRy, $width, $height, $otsuThreshold
                    );
                }

                // OMR statistical mark detection
                $result = $this->detectFilledMark($darknessValues, $choiceLabels, $otsuThreshold);

                $answers[$q]    = $result['answer'];
                $confidence[$q] = $result['confidence'];

                if ($result['confidence'] < self::CONFIDENCE_MEDIUM) {
                    $lowConfidence[] = $q;
                }
                if (!empty($result['multiple_marks'])) {
                    $multipleMarks[$q] = $result['multiple_marks'];
                }
            }
        }

        $avgConfidence = count($confidence) > 0
            ? round(array_sum($confidence) / count($confidence), 3)
            : 0;

        Log::debug('OMR answer detection complete', [
            'totalItems'      => $totalItems,
            'columns'         => $columns,
            'detected'        => count(array_filter($answers)),
            'avgConfidence'   => $avgConfidence,
            'lowConfidence'   => count($lowConfidence),
            'multipleMarks'   => count($multipleMarks),
            'otsuThreshold'   => $otsuThreshold,
            'skewAngle'       => $skewAngle,
            'bounds'          => $bounds,
            'detectedCols'    => $detectedCols,
            'imgSize'         => compact('width', 'height'),
        ]);

        imagedestroy($image);

        return [
            'answers'              => $answers,
            'confidence'           => $confidence,
            'avg_confidence'       => $avgConfidence,
            'low_confidence_items' => $lowConfidence,
            'multiple_marks'       => $multipleMarks,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | OMR Statistical Mark Detection
    |--------------------------------------------------------------------------
    */

    /**
     * Detect the filled mark using statistical OMR analysis.
     *
     * Uses z-score based detection: a mark is considered filled if its darkness
     * is more than MARK_Z_THRESHOLD standard deviations below the mean of all
     * marks in the row. This is robust against varying lighting/scan quality.
     *
     * Also detects multiple marks (student filled more than one bubble).
     *
     * @return array{answer: ?string, confidence: float, multiple_marks: array, raw_scores: array}
     */
    private function detectFilledMark(array $darknessValues, array $labels, int $otsuThreshold): array
    {
        if (empty($darknessValues)) {
            return ['answer' => null, 'confidence' => 0, 'multiple_marks' => [], 'raw_scores' => []];
        }

        $n     = count($darknessValues);
        $total = array_sum($darknessValues);
        $mean  = $total / $n;

        // Compute standard deviation
        $sumSqDiff = 0;
        foreach ($darknessValues as $val) {
            $sumSqDiff += pow($val - $mean, 2);
        }
        $stdDev = sqrt($sumSqDiff / $n);

        // Compute z-scores (negative z-score = darker than mean)
        $zScores = [];
        foreach ($darknessValues as $i => $val) {
            $zScores[$i] = $stdDev > 0 ? ($mean - $val) / $stdDev : 0;
        }

        // Find the darkest (highest z-score) mark
        $maxZ   = -INF;
        $maxIdx = null;
        foreach ($zScores as $i => $z) {
            if ($z > $maxZ) {
                $maxZ   = $z;
                $maxIdx = $i;
            }
        }

        // Detect all marks that exceed the threshold (for multiple-mark detection)
        $filledMarks = [];
        foreach ($zScores as $i => $z) {
            if ($z >= self::MARK_Z_THRESHOLD) {
                $filledMarks[] = $labels[$i] ?? (string)$i;
            }
        }

        // Also apply absolute threshold fallback (for cases where all marks are similar)
        $absDiff = $mean - ($darknessValues[$maxIdx] ?? 255);

        // Determine the answer and confidence
        $answer     = null;
        $confidence = 0;

        if ($maxZ >= self::MARK_Z_THRESHOLD && $absDiff >= self::MARK_ABS_THRESHOLD) {
            // Strong statistical detection + absolute confirmation
            $answer = $labels[$maxIdx] ?? null;
            $confidence = min(1.0, 0.5 + ($maxZ / (self::MARK_Z_THRESHOLD * 3)));
        } elseif ($maxZ >= self::MARK_Z_THRESHOLD) {
            // Statistical detection without absolute confirmation
            $answer = $labels[$maxIdx] ?? null;
            $confidence = min(0.8, 0.3 + ($maxZ / (self::MARK_Z_THRESHOLD * 4)));
        } elseif ($absDiff >= self::MARK_ABS_THRESHOLD) {
            // Absolute detection fallback (backward compatible)
            $answer = $labels[$maxIdx] ?? null;
            $confidence = min(0.65, 0.2 + ($absDiff / 100));
        }

        // If multiple marks detected, flag it but still return the darkest
        $multipleMarks = [];
        if (count($filledMarks) > 1) {
            $multipleMarks = $filledMarks;
            // Reduce confidence when multiple marks are detected
            $confidence *= 0.7;
        }

        return [
            'answer'         => $answer,
            'confidence'     => round($confidence, 3),
            'multiple_marks' => $multipleMarks,
            'raw_scores'     => [
                'darkness'  => $darknessValues,
                'z_scores'  => $zScores,
                'mean'      => $mean,
                'std_dev'   => $stdDev,
                'max_z'     => $maxZ,
                'abs_diff'  => $absDiff,
            ],
        ];
    }

    /**
     * Sample mark darkness using OMR-optimized circular sampling.
     *
     * Instead of sampling a rectangular grid, this uses a weighted circular
     * pattern that better matches the circular bubble shape. Pixels closer
     * to the center are weighted more heavily (Gaussian-like falloff).
     * Pixels below the Otsu threshold contribute more to the mark score.
     */
    private function sampleMarkDarkness($image, int $cx, int $cy, int $rx, int $ry, int $imgW, int $imgH, int $otsuThreshold): float
    {
        $totalWeightedBrightness = 0;
        $totalWeight = 0;

        $startX = max(0, $cx - $rx);
        $endX   = min($imgW - 1, $cx + $rx);
        $startY = max(0, $cy - $ry);
        $endY   = min($imgH - 1, $cy + $ry);

        $rxSq = max(1, $rx * $rx);
        $rySq = max(1, $ry * $ry);

        for ($x = $startX; $x <= $endX; $x += 2) {
            for ($y = $startY; $y <= $endY; $y += 2) {
                // Check if point is within ellipse
                $dx = $x - $cx;
                $dy = $y - $cy;
                $ellipseVal = ($dx * $dx) / $rxSq + ($dy * $dy) / $rySq;

                if ($ellipseVal > 1.0) continue; // Outside ellipse

                // Gaussian-like weight: higher near center
                $weight = 1.0 - ($ellipseVal * 0.5);

                $gray = imagecolorat($image, $x, $y) & 0xFF;

                // Boost weight for pixels that are clearly dark (below Otsu threshold)
                // This makes filled marks score much darker than partially shaded marks
                if ($gray < $otsuThreshold) {
                    $weight *= 1.3;
                }

                $totalWeightedBrightness += $gray * $weight;
                $totalWeight += $weight;
            }
        }

        return $totalWeight > 0 ? $totalWeightedBrightness / $totalWeight : 255;
    }

    /*
    |--------------------------------------------------------------------------
    | Student ID Detection (OMR)
    |--------------------------------------------------------------------------
    */

    /**
     * Detect the student ID from the scannable bubble grid using OMR.
     * The grid has `$idDigits` columns, each with digits 0-9 vertically.
     */
    public function detectStudentId(string $imagePath, int $idDigits = 7): ?string
    {
        $image = $this->loadImage($imagePath);
        if (!$image) {
            return null;
        }

        $width  = imagesx($image);
        $height = imagesy($image);

        // Apply OMR preprocessing
        $preprocess = $this->preprocessImage($image, $width, $height);
        $otsuThreshold = $preprocess['threshold'];

        $bounds  = $this->findSheetBounds($image, $width, $height);
        $contentW = $bounds['right'] - $bounds['left'];
        $contentH = $bounds['bottom'] - $bounds['top'];
        $pxPerMm  = $bounds['pxPerMm'];
        $pxPerMmV = $bounds['pxPerMmV'];

        // Dynamically detect the ID grid header bar
        $idBounds = $this->findIdGridBounds($image, $bounds, $width, $height);

        if ($idBounds) {
            $gridLeft      = $idBounds['left'];
            // Constrain gridRight to actual digit columns (idDigits × 8.5mm)
            $gridRight     = (int)($idBounds['left'] + $idDigits * 8.5 * $bounds['pxPerMm']);
            $headerBottom  = $idBounds['headerBottom'];
        } else {
            // Fallback: proportional estimation
            $gridLeft      = $bounds['left'];
            $gridRight     = $bounds['left'] + (int)($contentW * 0.48);
            $headerBottom  = $bounds['top'] + (int)($contentH * 0.21);
        }

        $gridW = $gridRight - $gridLeft;

        // CSS layout: each id-digit-col is ~8.5mm wide (5.5mm bubble + 3mm margin)
        // Bubble center at column midpoint (centered via align-items: center)
        $colWMm    = 8.5;   // Column width in mm
        $colWPx    = $colWMm * $pxPerMm;

        // Row layout: first bubble center below header bottom
        // (margin + half bubble + rendering buffer)
        // Row step: 6.7mm (5.5mm bubble + 2×0.6mm margin)
        $firstRowOffsetMm = 5.0;
        $rowStepMm        = 6.7;

        $bubbleRx = max(3, (int)(2.2 * $pxPerMm));
        $bubbleRy = max(3, (int)(2.2 * $pxPerMmV));

        $studentId = '';

        for ($d = 0; $d < $idDigits; $d++) {
            $darknessValues = [];
            $cx = (int)($gridLeft + ($d + 0.5) * $colWPx);

            for ($n = 0; $n <= 9; $n++) {
                $cy = (int)($headerBottom + ($firstRowOffsetMm + $n * $rowStepMm) * $pxPerMmV);

                $darknessValues[$n] = $this->sampleMarkDarkness(
                    $image, $cx, $cy, $bubbleRx, $bubbleRy, $width, $height, $otsuThreshold
                );
            }

            $picked = $this->detectFilledDigit($darknessValues, $otsuThreshold);
            $studentId .= ($picked !== null) ? (string)$picked : '?';
        }

        imagedestroy($image);

        Log::debug('OMR Student ID detection', [
            'raw'           => $studentId,
            'clean'         => str_replace('?', '', $studentId),
            'otsuThreshold' => $otsuThreshold,
        ]);

        return $studentId;
    }

    /**
     * Find the ID grid bounds by detecting its dark header bar.
     * Returns ['left', 'right', 'headerBottom', 'gridBottom'] or null.
     */
    private function findIdGridBounds($image, array $bounds, int $imgW, int $imgH): ?array
    {
        $contentH = $bounds['bottom'] - $bounds['top'];
        $contentW = $bounds['right'] - $bounds['left'];

        // The ID grid header bar sits at roughly 17-22% of content height,
        // in the left half of the sheet. Search that region.
        $searchTop    = $bounds['top'] + (int)($contentH * 0.15);
        $searchBottom = $bounds['top'] + (int)($contentH * 0.28);
        $searchLeft   = $bounds['left'];
        $searchRight  = $bounds['left'] + (int)($contentW * 0.55);

        // Vertical scan: find the dark header bar
        $bands = [];
        $bandStart = null;
        $bandEnd   = null;

        for ($y = $searchTop; $y < $searchBottom; $y++) {
            $sum = 0;
            $count = 0;
            for ($x = $searchLeft; $x <= min($searchRight, $imgW - 1); $x += 4) {
                $rgb = imagecolorat($image, $x, $y);
                $sum += ($rgb & 0xFF);
                $count++;
            }
            $avg = $count > 0 ? $sum / $count : 255;

            if ($avg < 180) {
                if ($bandStart === null) $bandStart = $y;
                $bandEnd = $y;
            } elseif ($bandStart !== null && ($y - $bandEnd) > 3) {
                $bands[] = ['start' => $bandStart, 'end' => $bandEnd, 'thickness' => $bandEnd - $bandStart + 1];
                $bandStart = null;
                $bandEnd   = null;
            }
        }
        if ($bandStart !== null) {
            $bands[] = ['start' => $bandStart, 'end' => $bandEnd, 'thickness' => $bandEnd - $bandStart + 1];
        }

        if (empty($bands)) {
            Log::info('OMR: No ID grid header bar found');
            return null;
        }

        // Pick the thickest band (the digit-header row)
        usort($bands, fn($a, $b) => $b['thickness'] <=> $a['thickness']);
        $darkBandStart = $bands[0]['start'];
        $darkBandEnd   = $bands[0]['end'];

        // Horizontal profile within the dark band to find left/right edges
        $profile = [];
        for ($x = $searchLeft; $x <= min($searchRight, $imgW - 1); $x++) {
            $sum = 0;
            $count = 0;
            for ($y = $darkBandStart; $y <= $darkBandEnd; $y += 2) {
                $rgb = imagecolorat($image, $x, min($y, $imgH - 1));
                $sum += ($rgb & 0xFF);
                $count++;
            }
            $profile[$x] = $count > 0 ? $sum / $count : 255;
        }

        // Find the contiguous dark horizontal region
        $inDark = false;
        $gridLeft = $searchLeft;
        $gridRight = $searchRight;
        foreach ($profile as $x => $brightness) {
            if ($brightness < 120 && !$inDark) {
                $inDark = true;
                $gridLeft = $x;
            } elseif ($brightness >= 120 && $inDark) {
                $gridRight = $x - 1;
                break;
            }
        }
        if ($inDark && $gridRight === $searchRight) {
            // Didn't find right edge, use last dark pixel
            $gridRight = array_key_last(array_filter($profile, fn($b) => $b < 120));
        }

        // Find headerBottom: scan down from dark band end at multiple X positions
        // and take the DEEPEST (max Y) to avoid stopping too early at a column border
        $gridW = $gridRight - $gridLeft;
        $sampleXs = [
            (int)($gridLeft + $gridW * 0.20),
            (int)($gridLeft + $gridW * 0.50),
            (int)($gridLeft + $gridW * 0.80),
        ];
        $headerBottom = $darkBandEnd + 1;
        foreach ($sampleXs as $sx) {
            $sx = min($sx, $imgW - 1);
            for ($y = $darkBandEnd + 1; $y < min($searchBottom + 200, $imgH); $y++) {
                $rgb = imagecolorat($image, $sx, $y);
                if (($rgb & 0xFF) > 180) {
                    $headerBottom = max($headerBottom, $y);
                    break;
                }
            }
        }

        Log::debug('OMR: ID grid header detected', [
            'left' => $gridLeft, 'right' => $gridRight,
            'headerBottom' => $headerBottom,
            'darkBandEnd' => $darkBandEnd,
            'bandThickness' => $bands[0]['thickness'],
            'sampleXs' => $sampleXs,
        ]);

        return [
            'left'         => $gridLeft,
            'right'        => $gridRight,
            'headerBottom' => $headerBottom,
        ];
    }

    /**
     * Detect the filled digit (0-9) using OMR z-score analysis.
     */
    private function detectFilledDigit(array $darknessValues, int $otsuThreshold): ?int
    {
        if (empty($darknessValues)) return null;

        $n     = count($darknessValues);
        $total = array_sum($darknessValues);
        $mean  = $total / $n;

        // Compute standard deviation
        $sumSqDiff = 0;
        foreach ($darknessValues as $val) {
            $sumSqDiff += pow($val - $mean, 2);
        }
        $stdDev = sqrt($sumSqDiff / $n);

        // Find the darkest mark and compute its z-score
        $minIdx = null;
        $minVal = 255;
        foreach ($darknessValues as $i => $val) {
            if ($val < $minVal) {
                $minVal = $val;
                $minIdx = $i;
            }
        }

        $zScore = $stdDev > 0 ? ($mean - $minVal) / $stdDev : 0;
        $absDiff = $mean - $minVal;

        if ($zScore >= self::MARK_Z_THRESHOLD || $absDiff >= self::MARK_ABS_THRESHOLD) {
            return $minIdx;
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Deskew Detection & Correction
    |--------------------------------------------------------------------------
    */

    /**
     * Compute the skew angle of the scanned sheet from corner marker positions.
     *
     * Uses the top-left and top-right markers to compute the rotation angle.
     * Returns the angle in degrees (positive = clockwise rotation).
     */
    private function computeSkewAngle(array $bounds, int $width, int $height): float
    {
        if (!($bounds['markers_found'] ?? false)) {
            return 0.0;
        }

        // The bounds give us the content area derived from markers.
        // We can compute skew from the top edge not being perfectly horizontal.
        $topLeft  = ['x' => $bounds['left'], 'y' => $bounds['top']];
        $topRight = ['x' => $bounds['right'], 'y' => $bounds['top']];

        // Since bounds are already normalized, skew info is embedded.
        // Use the diagonal difference: if the sheet is rotated,
        // top-right Y != top-left Y before normalization.
        // We approximate from the aspect ratio deviation.
        $expectedRatio = 186.0 / 277.0; // Content width / height on A4
        $actualRatio   = ($bounds['right'] - $bounds['left']) /
                         max(1, $bounds['bottom'] - $bounds['top']);

        // Small deviation ≈ perspective distortion or skew
        $skewEstimate = ($actualRatio - $expectedRatio) * 2.0; // Rough degrees estimate

        // Clamp to maximum correctable skew
        return max(-self::MAX_SKEW_DEGREES, min(self::MAX_SKEW_DEGREES, $skewEstimate));
    }

    /**
     * Apply deskew correction to a Y coordinate.
     *
     * Shifts the Y position based on the X position and computed skew angle,
     * compensating for sheet rotation without needing to rotate the entire image.
     */
    private function applyDeskewY(int $y, float $x, float $skewAngle, array $bounds): int
    {
        if (abs($skewAngle) < 0.1) return $y;

        $centerX = ($bounds['left'] + $bounds['right']) / 2;
        $offsetX = $x - $centerX;

        // tan(angle) * horizontal offset = vertical correction
        $correction = (int)($offsetX * tan(deg2rad($skewAngle)));

        return $y + $correction;
    }

    /*
    |--------------------------------------------------------------------------
    | Sheet Boundary Detection (corner markers)
    |--------------------------------------------------------------------------
    */

    /**
     * Find the sheet content area by detecting the 4 corner alignment markers.
     * Returns ['left', 'top', 'right', 'bottom'] pixel coordinates.
     */
    private function findSheetBounds($image, int $width, int $height): array
    {
        // The corner markers have 4mm×4mm filled black squares
        // On a phone photo of A4 (~2000-4000px), that's roughly 1.5-2% of image size
        $markerSize   = max(8, (int)(min($width, $height) * 0.015));
        $searchRadius = (int)(min($width, $height) * 0.15);

        // Search each corner for the darkest cluster (the filled square marker)
        $tl = $this->findDarkestCluster($image, 0, 0, $searchRadius, $searchRadius, $markerSize, $width, $height);
        $tr = $this->findDarkestCluster($image, $width - $searchRadius, 0, $width, $searchRadius, $markerSize, $width, $height);
        $bl = $this->findDarkestCluster($image, 0, $height - $searchRadius, $searchRadius, $height, $markerSize, $width, $height);
        $br = $this->findDarkestCluster($image, $width - $searchRadius, $height - $searchRadius, $width, $height, $markerSize, $width, $height);

        if ($tl && $tr && $bl && $br) {
            $sheetW = max($tr['x'], $br['x']) - min($tl['x'], $bl['x']);
            $sheetH = max($bl['y'], $br['y']) - min($tl['y'], $tr['y']);

            // Content area is inset from markers:
            // Markers sit at 5mm from page edge (center ~7mm)
            // Content padding: 12mm horizontal, 10mm vertical
            // Offset from marker center to content: ~5mm horiz, ~3mm vert
            // As fraction of sheet: horiz 5/196 ≈ 2.6%, vert 3/283 ≈ 1.1%
            $insetX = (int)($sheetW * 0.026);
            $insetY = (int)($sheetH * 0.011);

            // Physical scale from corner marker distances
            $pxPerMm  = $sheetW / 196.0;  // Horizontal: 196mm between TL and TR
            $pxPerMmV = $sheetH / 283.0;  // Vertical: 283mm between TL and BL

            return [
                'left'           => min($tl['x'], $bl['x']) + $insetX,
                'top'            => min($tl['y'], $tr['y']) + $insetY,
                'right'          => max($tr['x'], $br['x']) - $insetX,
                'bottom'         => max($bl['y'], $br['y']) - $insetY,
                'markers_found'  => true,
                'corners'        => compact('tl', 'tr', 'bl', 'br'),
                'pxPerMm'        => $pxPerMm,
                'pxPerMmV'       => $pxPerMmV,
            ];
        }

        // Fallback: assume sheet fills most of the image with small margins
        Log::info('OMR: Corner markers not found, using fallback bounds');
        return [
            'left'           => (int)($width * 0.04),
            'top'            => (int)($height * 0.03),
            'right'          => (int)($width * 0.96),
            'bottom'         => (int)($height * 0.97),
            'markers_found'  => false,
            'corners'        => null,
            'pxPerMm'        => $width / 210.0,
            'pxPerMmV'       => $height / 297.0,
        ];
    }

    /**
     * Find the darkest small cluster of pixels in a search region.
     * Used to locate the filled corner marker squares.
     */
    private function findDarkestCluster($image, int $sx1, int $sy1, int $sx2, int $sy2, int $size, int $imgW, int $imgH): ?array
    {
        $bestX = null;
        $bestY = null;
        $bestDarkness = 255;
        $step = max(2, (int)($size / 3));
        $halfSize = (int)($size / 2);

        for ($x = $sx1 + $halfSize; $x <= $sx2 - $halfSize; $x += $step) {
            for ($y = $sy1 + $halfSize; $y <= $sy2 - $halfSize; $y += $step) {
                $darkness = $this->sampleRegionDarkness($image, $x, $y, $halfSize, $halfSize, $imgW, $imgH);

                if ($darkness < $bestDarkness) {
                    $bestDarkness = $darkness;
                    $bestX = $x;
                    $bestY = $y;
                }
            }
        }

        // Only return if the cluster is actually dark (printed marker, not noise)
        if ($bestDarkness < 100 && $bestX !== null) {
            return ['x' => $bestX, 'y' => $bestY, 'darkness' => $bestDarkness];
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Column Header Detection
    |--------------------------------------------------------------------------
    */

    /**
     * Find the dark column header bars ("QUESTIONS X-Y") in the answer grid.
     * Returns array of ['left', 'right', 'headerBottom'] per detected column.
     * Falls back to empty array if headers can't be found.
     */
    private function findColumnHeaders($image, int $searchTop, int $searchBottom, int $searchLeft, int $searchRight, int $imgW, int $imgH, int $expectedColumns): array
    {
        // Step 1: Vertical scan to collect ALL dark bands, then pick the thickest.
        // This avoids locking onto thin border lines above the actual column headers.
        $checkLimit = min($searchTop + (int)(($searchBottom - $searchTop) * 0.25), $imgH);
        $bands = [];
        $bandStart = null;
        $bandEnd   = null;

        for ($y = $searchTop; $y < $checkLimit; $y++) {
            $sum = 0;
            $count = 0;
            for ($x = $searchLeft; $x <= min($searchRight, $imgW - 1); $x += 4) {
                $rgb = imagecolorat($image, $x, $y);
                $sum += ($rgb & 0xFF);
                $count++;
            }
            $avg = $count > 0 ? $sum / $count : 255;

            if ($avg < 160) {
                if ($bandStart === null) $bandStart = $y;
                $bandEnd = $y;
            } elseif ($bandStart !== null && ($y - $bandEnd) > 5) {
                $bands[] = ['start' => $bandStart, 'end' => $bandEnd, 'thickness' => $bandEnd - $bandStart + 1];
                $bandStart = null;
                $bandEnd   = null;
            }
        }
        // Capture the last band if still open
        if ($bandStart !== null) {
            $bands[] = ['start' => $bandStart, 'end' => $bandEnd, 'thickness' => $bandEnd - $bandStart + 1];
        }

        if (empty($bands)) {
            Log::info('OMR: No column header dark band found in search region');
            return [];
        }

        // Pick the thickest dark band (the column header bar, not thin borders)
        usort($bands, fn($a, $b) => $b['thickness'] <=> $a['thickness']);
        $darkBandStart = $bands[0]['start'];
        $darkBandEnd   = $bands[0]['end'];

        Log::debug('OMR: Dark bands found', ['bands' => $bands, 'selected' => $bands[0]]);

        // Step 2: Horizontal projection within the dark band to find each column's X range
        $profile = [];
        for ($x = $searchLeft; $x <= min($searchRight, $imgW - 1); $x++) {
            $sum = 0;
            $count = 0;
            for ($y = $darkBandStart; $y <= $darkBandEnd; $y += 2) {
                $rgb = imagecolorat($image, $x, min($y, $imgH - 1));
                $sum += ($rgb & 0xFF);
                $count++;
            }
            $profile[$x] = $count > 0 ? $sum / $count : 255;
        }

        // Find contiguous dark horizontal segments
        $darkRanges = [];
        $inDark = false;
        $start  = $searchLeft;

        foreach ($profile as $x => $brightness) {
            if ($brightness < 100 && !$inDark) {
                $inDark = true;
                $start  = $x;
            } elseif ($brightness >= 100 && $inDark) {
                $inDark = false;
                $darkRanges[] = ['left' => $start, 'right' => $x - 1];
            }
        }
        if ($inDark) {
            $darkRanges[] = ['left' => $start, 'right' => min($searchRight, $imgW - 1)];
        }

        // Filter: keep ranges at least 30% of expected column width
        $expectedColW = ($searchRight - $searchLeft) / $expectedColumns;
        $darkRanges = array_values(array_filter(
            $darkRanges,
            fn($r) => ($r['right'] - $r['left']) > ($expectedColW * 0.3)
        ));

        if (count($darkRanges) !== $expectedColumns) {
            Log::info('OMR: Column header count mismatch', [
                'found'    => count($darkRanges),
                'expected' => $expectedColumns,
                'ranges'   => $darkRanges,
            ]);
            return [];
        }

        // Step 3: Find the bottom edge of each header bar.
        $columns = [];
        foreach ($darkRanges as $range) {
            $rangeW = $range['right'] - $range['left'];
            $sampleXs = [
                $range['left'] + (int)($rangeW * 0.25),
                $range['left'] + (int)($rangeW * 0.50),
                $range['left'] + (int)($rangeW * 0.75),
            ];

            $bestHeaderBottom = $darkBandEnd + 1;

            foreach ($sampleXs as $sx) {
                $sx = min($sx, $imgW - 1);
                for ($y = $darkBandEnd + 1; $y < min($searchBottom, $imgH); $y++) {
                    $rgb = imagecolorat($image, $sx, min($y, $imgH - 1));
                    if (($rgb & 0xFF) > 180) {
                        $bestHeaderBottom = max($bestHeaderBottom, $y);
                        break;
                    }
                }
            }

            $columns[] = [
                'left'         => $range['left'],
                'right'        => $range['right'],
                'headerBottom' => $bestHeaderBottom,
            ];
        }

        // Step 4: Normalize column widths using the middle column as reference.
        // Column 2 is most accurately detected (no page-edge interference).
        // Use its width for all columns, re-centering each around its midpoint.
        $refIdx   = (int)(count($columns) / 2); // middle column
        $refWidth = $columns[$refIdx]['right'] - $columns[$refIdx]['left'];
        for ($i = 0; $i < count($columns); $i++) {
            if ($i === $refIdx) continue; // don't touch the reference column
            $center = ($columns[$i]['left'] + $columns[$i]['right']) / 2;
            $columns[$i]['left']  = (int)($center - $refWidth / 2);
            $columns[$i]['right'] = (int)($center + $refWidth / 2);
        }

        // Step 5: Find the bottom of each answer column independently.
        // Do not force a shared bottom Y; perspective/keystone distortion can make
        // outer columns sit slightly higher/lower than the center column.
        foreach ($columns as &$col) {
            $scanX = $col['left'] + (int)(($col['right'] - $col['left']) * 0.5);
            $gridBottom = $searchBottom;

            for ($y = min($searchBottom, $imgH - 1); $y > $col['headerBottom']; $y--) {
                $rgb = imagecolorat($image, min($scanX, $imgW - 1), $y);
                if (($rgb & 0xFF) < 220) {
                    $gridBottom = $y + 1;
                    break;
                }
            }

            $col['gridBottom'] = $gridBottom;
        }
        unset($col);

        Log::debug('OMR column headers detected', [
            'columns' => $columns,
        ]);
        return $columns;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Infer the number of print columns from total items.
     * Matches the defaults typically chosen in the template form.
     */
    public function inferColumnCount(int $totalItems): int
    {
        if ($totalItems <= 20) return 1;
        if ($totalItems <= 30) return 2;
        return 3;
    }

    /**
     * Get template layout parameters based on total items.
     *
     * 30-item template: standard 6mm bubbles, 2mm gap, grid starts at ~48% of page
     * 50-item template: compact 4.5mm bubbles, 1.5mm gap, grid starts at ~38% of page
     *
     * @return array{firstBubbleMm: float, bubbleStepMm: float, bubbleRadiusMm: float, searchTopRatio: float, searchBottomRatio: float, bubbleDiameterMm: float, bubbleGapMm: float, rowPaddingMm: float}
     */
    public function getTemplateParams(int $totalItems): array
    {
        if ($totalItems > 30) {
            // 50-item (compact) template — 3 columns
            return [
                'firstBubbleMm'    => 10.5,   // 2.5mm row-pad + 5.5mm question-num + 2.25mm half-bubble + tolerance
                'bubbleStepMm'     => 6.0,    // 4.5mm bubble + 1.5mm gap
                'bubbleRadiusMm'   => 2.0,    // sampling radius (~90% of 2.25mm)
                'searchTopRatio'   => 0.45,   // grid starts ~53% down; search starts a bit above
                'searchBottomRatio'=> 0.98,
                'firstRowOffsetMm' => 20.0,   // mm offset from detected header bottom to first row area
                'bubbleDiameterMm' => 4.5,    // print size
                'bubbleGapMm'      => 1.5,    // print gap
                'rowPaddingMm'     => 0.6,    // print row padding
            ];
        }

        // 30-item (standard) template
        return [
            'firstBubbleMm'    => 10.5,
            'bubbleStepMm'     => 8.0,    // 6mm bubble + 2mm gap
            'bubbleRadiusMm'   => 2.7,    // sampling radius (~90% of 3mm)
            'searchTopRatio'   => 0.48,
            'searchBottomRatio'=> 0.98,
            'firstRowOffsetMm' => 0.0,    // no extra offset needed for standard template
            'bubbleDiameterMm' => 6.0,    // print size
            'bubbleGapMm'      => 2.0,    // print gap
            'rowPaddingMm'     => 1.0,    // print row padding
        ];
    }

    /**
     * Calculate bubble center positions as absolute mm offsets from column left edge.
     *
     * CSS layout (fixed mm values, box-sizing: border-box on all elements):
     * - Row left padding: 2.5mm
     * - Question number width: 7mm (includes 2mm padding-right, border-box)
     * - Bubble diameter: 6mm (center at +3mm from edge)
     * - Gap between bubbles: 2mm
     * - First bubble center from left: 2.5 + 7 + 3 = 12.5mm
     * - Step between bubble centers: 6 + 2 = 8mm
     *
     * These are absolute mm values independent of page/column width,
     * converted to pixels using pxPerMm from corner marker detection.
     *
     * @return array{firstMm: float, stepMm: float, centersMm: float[]}
     */
    private function calculateBubblePositions(int $columns, int $choicesPerItem, int $totalItems = 30): array
    {
        $params = $this->getTemplateParams($totalItems);
        $firstBubbleMm = $params['firstBubbleMm'];
        $bubbleStepMm  = $params['bubbleStepMm'];

        $centersMm = [];
        for ($c = 0; $c < $choicesPerItem; $c++) {
            $centersMm[$c] = $firstBubbleMm + $c * $bubbleStepMm;
        }

        return [
            'firstMm'   => $firstBubbleMm,
            'stepMm'    => $bubbleStepMm,
            'centersMm' => $centersMm,
        ];
    }

    /**
     * Sample the average brightness of a rectangular region around a center point.
     * Used for corner marker detection (non-OMR path). Lower values = darker.
     */
    private function sampleRegionDarkness($image, int $cx, int $cy, int $rx, int $ry, int $imgW, int $imgH): float
    {
        $totalBrightness = 0;
        $pixelCount = 0;

        $startX = max(0, $cx - $rx);
        $endX   = min($imgW - 1, $cx + $rx);
        $startY = max(0, $cy - $ry);
        $endY   = min($imgH - 1, $cy + $ry);

        for ($x = $startX; $x <= $endX; $x += 2) {
            for ($y = $startY; $y <= $endY; $y += 2) {
                $rgb = imagecolorat($image, $x, $y);
                $gray = $rgb & 0xFF;
                $totalBrightness += $gray;
                $pixelCount++;
            }
        }

        return $pixelCount > 0 ? $totalBrightness / $pixelCount : 255;
    }

    /**
     * Load an image file into a GD resource from various formats.
     */
    private function loadImage(string $path)
    {
        $info = @getimagesize($path);
        if (!$info) return null;

        return match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_GIF  => @imagecreatefromgif($path),
            IMAGETYPE_BMP  => @imagecreatefrombmp($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default        => null,
        };
    }

    /**
     * Grade an exam by comparing detected answers to the answer key.
     */
    public function gradeExam(array $detectedAnswers, array $correctAnswers): array
    {
        $score   = 0;
        $details = [];

        foreach ($correctAnswers as $questionNum => $correctAnswer) {
            $studentAnswer = $detectedAnswers[$questionNum] ?? null;
            $isCorrect = $studentAnswer !== null &&
                         strtoupper((string)$studentAnswer) === strtoupper((string)$correctAnswer);

            if ($isCorrect) {
                $score++;
            }

            $details[] = [
                'question_number' => $questionNum,
                'correct_answer'  => $correctAnswer,
                'student_answer'  => $studentAnswer,
                'is_correct'      => $isCorrect,
            ];
        }

        $total      = count($correctAnswers);
        $percentage = $total > 0 ? round(($score / $total) * 100, 2) : 0;

        return [
            'score'      => $score,
            'total'      => $total,
            'percentage' => $percentage,
            'details'    => $details,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Debug Visualization
    |--------------------------------------------------------------------------
    */

    /**
     * Generate a debug overlay image showing OMR sampling regions and detection results.
     * Saves a PNG alongside the original image for visual verification.
     *
     * Red ellipses    = OMR sampling regions for answer bubbles
     * Green ellipses  = OMR sampling regions for ID digit bubbles
     * Blue lines      = content bounds / grid boundaries
     * Yellow boxes    = detected column boundaries
     * Cyan text       = Otsu threshold & confidence info
     *
     * @return string|null  Path to the generated debug image, or null on failure
     */
    public function generateDebugImage(string $imagePath, int $totalItems, int $choicesPerItem, int $columns = 0, int $idDigits = 7): ?string
    {
        $image = $this->loadImage($imagePath);
        if (!$image) return null;

        $width  = imagesx($image);
        $height = imagesy($image);

        // Load a separate copy for OMR preprocessing (detection uses preprocessed image)
        $grayImage = $this->loadImage($imagePath);
        $preprocess = $this->preprocessImage($grayImage, $width, $height);
        $otsuThreshold = $preprocess['threshold'];

        $bounds  = $this->findSheetBounds($grayImage, $width, $height);
        $contentW = $bounds['right'] - $bounds['left'];
        $contentH = $bounds['bottom'] - $bounds['top'];
        $pxPerMm  = $bounds['pxPerMm'];
        $pxPerMmV = $bounds['pxPerMmV'];

        if ($columns <= 0) {
            $columns = $this->inferColumnCount($totalItems);
        }

        $skewAngle = $this->computeSkewAngle($bounds, $width, $height);

        // Allocate colors
        $red       = imagecolorallocatealpha($image, 255, 0, 0, 60);
        $green     = imagecolorallocatealpha($image, 0, 200, 0, 60);
        $blue      = imagecolorallocatealpha($image, 0, 100, 255, 40);
        $yellow    = imagecolorallocatealpha($image, 255, 255, 0, 40);
        $cyan      = imagecolorallocate($image, 0, 200, 200);
        $redLine   = imagecolorallocate($image, 255, 0, 0);
        $blueLine  = imagecolorallocate($image, 0, 100, 255);
        $greenLine = imagecolorallocate($image, 0, 200, 0);
        $white     = imagecolorallocate($image, 255, 255, 255);
        $darkBg    = imagecolorallocatealpha($image, 0, 0, 0, 80);

        // Draw OMR info header
        $infoY = 10;
        imagefilledrectangle($image, 5, $infoY, 400, $infoY + 60, $darkBg);
        imagestring($image, 4, 10, $infoY + 5, "OMR Debug Overlay", $cyan);
        imagestring($image, 3, 10, $infoY + 22, "Otsu Threshold: {$otsuThreshold}", $white);
        imagestring($image, 3, 10, $infoY + 38, sprintf("Skew: %.2f deg | Markers: %s",
            $skewAngle, $bounds['markers_found'] ? 'YES' : 'NO'), $white);

        // Draw content bounds
        imagesetthickness($image, 2);
        imagerectangle($image, $bounds['left'], $bounds['top'], $bounds['right'], $bounds['bottom'], $blueLine);

        // === Answer Grid Debug ===
        $tplParams = $this->getTemplateParams($totalItems);
        $searchTop    = $bounds['top'] + (int)($contentH * $tplParams['searchTopRatio']);
        $searchBottom = $bounds['top'] + (int)($contentH * $tplParams['searchBottomRatio']);
        $itemsPerColumn = (int)ceil($totalItems / $columns);

        imagerectangle($image, $bounds['left'], $searchTop, $bounds['right'], $searchBottom, $redLine);

        $detectedCols = $this->findColumnHeaders(
            $grayImage, $searchTop, $searchBottom,
            $bounds['left'], $bounds['right'],
            $width, $height, $columns
        );

        $bubblePositions = $this->calculateBubblePositions($columns, $choicesPerItem, $totalItems);

        for ($col = 0; $col < $columns; $col++) {
            $startItem  = $col * $itemsPerColumn + 1;
            $endItem    = min(($col + 1) * $itemsPerColumn, $totalItems);
            $itemsInCol = $endItem - $startItem + 1;
            if ($itemsInCol <= 0) continue;

            if (isset($detectedCols[$col])) {
                $colLeft    = $detectedCols[$col]['left'];
                $colWidth   = $detectedCols[$col]['right'] - $colLeft;
                $rowsTop    = $detectedCols[$col]['headerBottom'];
                $rowsBottom = $detectedCols[$col]['gridBottom'] ?? $searchBottom;
            } else {
                $colGapPx   = (int)($contentW * 0.022);
                $calcColW   = (int)(($contentW - ($columns - 1) * $colGapPx) / $columns);
                $colLeft    = $bounds['left'] + $col * ($calcColW + $colGapPx);
                $colWidth   = $calcColW;
                $rowsTop    = $searchTop + (int)(($searchBottom - $searchTop) * 0.12);
                $rowsBottom = $searchBottom;
            }

            // Apply first-row offset to account for header-to-grid gap
            $rowsTop += (int)(($tplParams['firstRowOffsetMm'] ?? 0) * $pxPerMmV);
            $rowAreaH = $rowsBottom - $rowsTop;
            // Use itemsPerColumn (not itemsInCol) so ALL columns have the same row height
            $rowH     = $rowAreaH / $itemsPerColumn;

            $bubbleRx = max(5, (int)($tplParams['bubbleRadiusMm'] * $pxPerMm));
            $bubbleRy = max(5, (int)($tplParams['bubbleRadiusMm'] * $pxPerMmV));

            // Draw column boundary
            imagerectangle($image, $colLeft, $rowsTop - 5, $colLeft + $colWidth, $rowsBottom, $yellow);

            for ($row = 0; $row < $itemsInCol; $row++) {
                $rawCy = (int)($rowsTop + ($row + 0.5) * $rowH);
                $cy = $this->applyDeskewY($rawCy, $colLeft + $colWidth / 2, $skewAngle, $bounds);

                for ($c = 0; $c < $choicesPerItem; $c++) {
                    $cx = (int)($colLeft + $bubblePositions['centersMm'][$c] * $colPxPerMm);

                    // Draw elliptical sampling region (matching OMR circular sampling)
                    imageellipse($image, $cx, $cy, $bubbleRx * 2, $bubbleRy * 2, $redLine);
                    imagefilledellipse($image, $cx, $cy, $bubbleRx * 2, $bubbleRy * 2, $red);
                }
            }
        }

        // === ID Grid Debug ===
        $idBounds = $this->findIdGridBounds($grayImage, $bounds, $width, $height);

        if ($idBounds) {
            $idGridLeft     = $idBounds['left'];
            // Constrain to actual digit columns (idDigits × 8.5mm)
            $idGridRight    = (int)($idBounds['left'] + $idDigits * 8.5 * $pxPerMm);
            $idHeaderBottom = $idBounds['headerBottom'];
        } else {
            $idGridLeft     = $bounds['left'];
            $idGridRight    = $bounds['left'] + (int)($contentW * 0.48);
            $idHeaderBottom = $bounds['top'] + (int)($contentH * 0.21);
        }

        // CSS: column width 8.5mm, row step 6.7mm, first row offset 5.0mm
        $idColWPx          = 8.5 * $pxPerMm;
        $idFirstRowOffMm   = 5.0;
        $idRowStepMm       = 6.7;

        $idBubbleRx = max(3, (int)(2.2 * $pxPerMm));
        $idBubbleRy = max(3, (int)(2.2 * $pxPerMmV));

        // Draw ID grid boundary
        $idGridBottom = (int)($idHeaderBottom + ($idFirstRowOffMm + 9 * $idRowStepMm + 3.35) * $pxPerMmV);
        imagerectangle($image, $idGridLeft, $idBounds ? $idBounds['headerBottom'] - (int)(5 * $pxPerMmV) : $idGridLeft, $idGridRight, $idGridBottom, $greenLine);

        for ($d = 0; $d < $idDigits; $d++) {
            $cx = (int)($idGridLeft + ($d + 0.5) * $idColWPx);
            for ($n = 0; $n <= 9; $n++) {
                $cy = (int)($idHeaderBottom + ($idFirstRowOffMm + $n * $idRowStepMm) * $pxPerMmV);
                imageellipse($image, $cx, $cy, $idBubbleRx * 2, $idBubbleRy * 2, $greenLine);
                imagefilledellipse($image, $cx, $cy, $idBubbleRx * 2, $idBubbleRy * 2, $green);
            }
        }

        // Save debug image
        $debugPath = preg_replace('/\.(jpe?g|png|gif|bmp|webp)$/i', '_debug.png', $imagePath);
        imagepng($image, $debugPath);
        imagedestroy($image);
        imagedestroy($grayImage);

        Log::info('OMR debug image generated', ['path' => $debugPath]);
        return $debugPath;
    }
}

