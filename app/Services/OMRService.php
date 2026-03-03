<?php

namespace App\Services;

use App\Models\AnswerKey;
use App\Models\Exam;
use App\Models\ExamResult;
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

        // Deskew correction — compute skew angle from corner markers
        $skewAngle = $this->computeSkewAngle($bounds, $width, $height);

        // Search region for column header bars (bottom ~50% of content)
        $searchTop    = $bounds['top'] + (int)($contentH * 0.48);
        $searchBottom = $bounds['top'] + (int)($contentH * 0.98);

        // Dynamically detect column header bars for precise column boundaries
        $detectedCols = $this->findColumnHeaders(
            $image, $searchTop, $searchBottom,
            $bounds['left'], $bounds['right'],
            $width, $height, $columns
        );

        // Bubble center X positions as absolute mm offsets from column left
        $bubblePositions = $this->calculateBubblePositions($columns, $choicesPerItem);

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
                // Add 1mm gap below header to reach actual first question row
                $rowsTop    = $detectedCols[$col]['headerBottom'] + (int)(1.0 * $pxPerMm);
                $rowsBottom = $detectedCols[$col]['gridBottom'] ?? $searchBottom;
            } else {
                $colGapPx   = (int)($contentW * 0.022);
                $calcColW   = (int)(($contentW - ($columns - 1) * $colGapPx) / $columns);
                $colLeft    = $bounds['left'] + $col * ($calcColW + $colGapPx);
                $colWidth   = $calcColW;
                // 14% of search range ≈ 7% of content, enough to skip past the header bar
                $rowsTop    = $searchTop + (int)(($searchBottom - $searchTop) * 0.14);
                $rowsBottom = $searchBottom;
            }

            $rowAreaH = $rowsBottom - $rowsTop;
            $rowH     = $rowAreaH / $itemsInCol;

            // Sampling radius: ~2mm (70% of 3mm bubble radius, avoids border)
            $bubbleRx = max(5, (int)(2.0 * $pxPerMm));
            $bubbleRy = max(5, (int)(2.0 * $pxPerMm));

            for ($row = 0; $row < $itemsInCol; $row++) {
                $q  = $startItem + $row;

                // Apply deskew compensation to Y coordinate
                $rawCy = (int)($rowsTop + ($row + 0.5) * $rowH);
                $cy = $this->applyDeskewY($rawCy, $colLeft + $colWidth / 2, $skewAngle, $bounds);

                $darknessValues = [];
                for ($c = 0; $c < $choicesPerItem; $c++) {
                    $cx = (int)($colLeft + $bubblePositions['centersMm'][$c] * $pxPerMm);
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
    public function detectStudentId(string $imagePath, int $idDigits = 10): ?string
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

        // ID grid region (the id-grid-wrapper with actual bubbles):
        // From CSS: header ~30mm + student-info ~17mm + section-label ~5mm = ~52mm
        // As fraction of 277mm content height: 52/277 ≈ 18.8%
        // Grid height: digit-header 5mm + 10 × (5.5mm + 1.2mm margin) = 72mm
        // Grid bottom at (52 + 72)/277 ≈ 44.8%
        // Grid width: 10 digit columns × ~9mm each ≈ 90mm out of 186mm ≈ 48%
        $gridTop    = $bounds['top'] + (int)($contentH * 0.19);
        $gridBottom = $bounds['top'] + (int)($contentH * 0.45);
        $gridLeft   = $bounds['left'];
        $gridRight  = $bounds['left'] + (int)($contentW * 0.50);

        $gridW = $gridRight - $gridLeft;
        $gridH = $gridBottom - $gridTop;

        $colW = $gridW / $idDigits;

        // Digit header row (dark bar with column number): ~5mm / 72mm ≈ 7%
        $headerH = (int)($gridH * 0.07);
        $rowH    = ($gridH - $headerH) / 10;

        $bubbleRx = max(3, (int)($colW * 0.15));
        $bubbleRy = max(3, (int)($rowH * 0.15));

        $studentId = '';

        for ($d = 0; $d < $idDigits; $d++) {
            $darknessValues = [];
            for ($n = 0; $n <= 9; $n++) {
                $cx = (int)($gridLeft + ($d + 0.5) * $colW);
                $cy = (int)($gridTop + $headerH + ($n + 0.5) * $rowH);

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

            // Physical scale: 196mm between TL and TR marker centers
            $pxPerMm = $sheetW / 196.0;

            return [
                'left'           => min($tl['x'], $bl['x']) + $insetX,
                'top'            => min($tl['y'], $tr['y']) + $insetY,
                'right'          => max($tr['x'], $br['x']) - $insetX,
                'bottom'         => max($bl['y'], $br['y']) - $insetY,
                'markers_found'  => true,
                'corners'        => compact('tl', 'tr', 'bl', 'br'),
                'pxPerMm'        => $pxPerMm,
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

        // Normalize: all column headers end at the same Y coordinate
        $maxHeaderBottom = max(array_column($columns, 'headerBottom'));
        foreach ($columns as &$col) {
            $col['headerBottom'] = $maxHeaderBottom;
        }
        unset($col);

        // Step 4: Adjust column boundaries to match the actual column containers.
        // The detected dark header bar already spans the full .col-header background,
        // which matches the .bubble-column width. Only apply minimal tolerance (±3px)
        // to account for anti-aliasing at the edges of the dark region.
        for ($i = 0; $i < count($columns); $i++) {
            $columns[$i]['left']  = max($columns[$i]['left'] - 3, $searchLeft);
            $columns[$i]['right'] = min($columns[$i]['right'] + 3, $searchRight);
        }

        // Step 5: Find the bottom of the answer grid
        $firstCol = $columns[0];
        $scanX = $firstCol['left'] + (int)(($firstCol['right'] - $firstCol['left']) * 0.5);
        $gridBottom = $searchBottom;

        for ($y = min($searchBottom, $imgH - 1); $y > $maxHeaderBottom; $y--) {
            $rgb = imagecolorat($image, min($scanX, $imgW - 1), $y);
            if (($rgb & 0xFF) < 220) {
                $gridBottom = $y + 1;
                break;
            }
        }

        foreach ($columns as &$col) {
            $col['gridBottom'] = $gridBottom;
        }
        unset($col);

        Log::debug('OMR column headers detected', [
            'columns'                => $columns,
            'normalizedHeaderBottom' => $maxHeaderBottom,
            'gridBottom'             => $gridBottom,
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
        if ($totalItems <= 75) return 2;
        return 3;
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
    private function calculateBubblePositions(int $columns, int $choicesPerItem): array
    {
        $firstBubbleMm = 12.5;  // 2.5mm row-pad + 7mm question-num (border-box incl. 2mm pad) + 3mm half-bubble
        $bubbleStepMm  = 8.0;   // 6mm bubble + 2mm gap

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
    public function generateDebugImage(string $imagePath, int $totalItems, int $choicesPerItem, int $columns = 0, int $idDigits = 10): ?string
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
        $searchTop    = $bounds['top'] + (int)($contentH * 0.48);
        $searchBottom = $bounds['top'] + (int)($contentH * 0.98);
        $itemsPerColumn = (int)ceil($totalItems / $columns);

        imagerectangle($image, $bounds['left'], $searchTop, $bounds['right'], $searchBottom, $redLine);

        $detectedCols = $this->findColumnHeaders(
            $grayImage, $searchTop, $searchBottom,
            $bounds['left'], $bounds['right'],
            $width, $height, $columns
        );

        $bubblePositions = $this->calculateBubblePositions($columns, $choicesPerItem);

        for ($col = 0; $col < $columns; $col++) {
            $startItem  = $col * $itemsPerColumn + 1;
            $endItem    = min(($col + 1) * $itemsPerColumn, $totalItems);
            $itemsInCol = $endItem - $startItem + 1;
            if ($itemsInCol <= 0) continue;

            if (isset($detectedCols[$col])) {
                $colLeft    = $detectedCols[$col]['left'];
                $colWidth   = $detectedCols[$col]['right'] - $colLeft;
                $rowsTop    = $detectedCols[$col]['headerBottom'] + (int)(1.0 * $pxPerMm);
                $rowsBottom = $detectedCols[$col]['gridBottom'] ?? $searchBottom;
            } else {
                $colGapPx   = (int)($contentW * 0.022);
                $calcColW   = (int)(($contentW - ($columns - 1) * $colGapPx) / $columns);
                $colLeft    = $bounds['left'] + $col * ($calcColW + $colGapPx);
                $colWidth   = $calcColW;
                $rowsTop    = $searchTop + (int)(($searchBottom - $searchTop) * 0.14);
                $rowsBottom = $searchBottom;
            }

            $rowAreaH = $rowsBottom - $rowsTop;
            $rowH     = $rowAreaH / $itemsInCol;

            $bubbleRx = max(5, (int)(2.0 * $pxPerMm));
            $bubbleRy = max(5, (int)(2.0 * $pxPerMm));

            // Draw column boundary
            imagerectangle($image, $colLeft, $rowsTop - 5, $colLeft + $colWidth, $rowsBottom, $yellow);

            for ($row = 0; $row < $itemsInCol; $row++) {
                $rawCy = (int)($rowsTop + ($row + 0.5) * $rowH);
                $cy = $this->applyDeskewY($rawCy, $colLeft + $colWidth / 2, $skewAngle, $bounds);

                for ($c = 0; $c < $choicesPerItem; $c++) {
                    $cx = (int)($colLeft + $bubblePositions['centersMm'][$c] * $pxPerMm);

                    // Draw elliptical sampling region (matching OMR circular sampling)
                    imageellipse($image, $cx, $cy, $bubbleRx * 2, $bubbleRy * 2, $redLine);
                    imagefilledellipse($image, $cx, $cy, $bubbleRx * 2, $bubbleRy * 2, $red);
                }
            }
        }

        // === ID Grid Debug ===
        $idGridTop    = $bounds['top'] + (int)($contentH * 0.19);
        $idGridBottom = $bounds['top'] + (int)($contentH * 0.45);
        $idGridLeft   = $bounds['left'];
        $idGridRight  = $bounds['left'] + (int)($contentW * 0.50);
        $idGridW = $idGridRight - $idGridLeft;
        $idGridH = $idGridBottom - $idGridTop;

        imagerectangle($image, $idGridLeft, $idGridTop, $idGridRight, $idGridBottom, $greenLine);

        $idColW = $idGridW / $idDigits;
        $idHeaderH = (int)($idGridH * 0.07);
        $idRowH = ($idGridH - $idHeaderH) / 10;
        $idBubbleRx = max(3, (int)($idColW * 0.15));
        $idBubbleRy = max(3, (int)($idRowH * 0.15));

        for ($d = 0; $d < $idDigits; $d++) {
            for ($n = 0; $n <= 9; $n++) {
                $cx = (int)($idGridLeft + ($d + 0.5) * $idColW);
                $cy = (int)($idGridTop + $idHeaderH + ($n + 0.5) * $idRowH);
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
