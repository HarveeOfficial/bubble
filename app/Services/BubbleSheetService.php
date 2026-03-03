<?php

namespace App\Services;

use App\Models\AnswerKey;
use App\Models\Exam;
use App\Models\ExamResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BubbleSheetService
{
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
     * Process an uploaded bubble sheet image against an answer key.
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

            // Detect answers from the bubble sheet image
            $detectedAnswers = $this->detectAnswers(
                $imagePath,
                $answerKey->total_items,
                $answerKey->choices_per_item,
                $columns
            );

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

            return $exam->fresh(['results']);
        } catch (\Exception $e) {
            $exam->update(['status' => 'failed']);
            Log::error('Bubble sheet processing failed: ' . $e->getMessage(), [
                'exam_id' => $exam->id,
                'trace'   => $e->getTraceAsString(),
            ]);
            return $exam;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Answer Detection (multi-column aware)
    |--------------------------------------------------------------------------
    */

    /**
     * Detect filled answer bubbles from a bubble sheet image.
     * Supports multi-column layouts matching the printed template.
     */
    public function detectAnswers(string $imagePath, int $totalItems, int $choicesPerItem, int $columns = 0): array
    {
        $image = $this->loadImage($imagePath);
        if (!$image) {
            Log::warning('Could not load image for answer detection', ['path' => $imagePath]);
            return array_fill(1, $totalItems, null);
        }

        $width  = imagesx($image);
        $height = imagesy($image);

        // Pre-processing: grayscale + contrast enhancement
        imagefilter($image, IMG_FILTER_GRAYSCALE);
        imagefilter($image, IMG_FILTER_CONTRAST, -30);

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
        $templateColWidthMm = $this->getTemplateColumnWidthMm($columns);

        $answers = [];

        for ($col = 0; $col < $columns; $col++) {
            $startItem  = $col * $itemsPerColumn + 1;
            $endItem    = min(($col + 1) * $itemsPerColumn, $totalItems);
            $itemsInCol = $endItem - $startItem + 1;
            if ($itemsInCol <= 0) continue;

            // Use detected column boundaries when available, else calculated
            if (isset($detectedCols[$col])) {
                $colLeft   = $detectedCols[$col]['left'];
                $colWidth  = $detectedCols[$col]['right'] - $colLeft;
                $rowsTop   = $detectedCols[$col]['headerBottom'];
                $rowsBottom = $detectedCols[$col]['gridBottom'] ?? $searchBottom;
            } else {
                $colGapPx  = (int)($contentW * 0.022);
                $calcColW  = (int)(($contentW - ($columns - 1) * $colGapPx) / $columns);
                $colLeft   = $bounds['left'] + $col * ($calcColW + $colGapPx);
                $colWidth  = $calcColW;
                $rowsTop   = $searchTop + (int)(($searchBottom - $searchTop) * 0.12);
                $rowsBottom = $searchBottom;
            }

            $rowAreaH = $rowsBottom - $rowsTop;
            $rowH     = $rowAreaH / $itemsInCol;

            // Use per-column horizontal scale to handle perspective distortion.
            $colPxPerMm = max(0.1, $colWidth / max(1.0, $templateColWidthMm));

            // Sampling radius follows local scale (X from column width, Y from row spacing).
            $bubbleRx = max(5, (int)(2.7 * $colPxPerMm));
            $bubbleRy = max(5, (int)(2.7 * $pxPerMmV));

            for ($row = 0; $row < $itemsInCol; $row++) {
                $q  = $startItem + $row;
                $cy = (int)($rowsTop + ($row + 0.5) * $rowH);

                $darknessValues = [];
                for ($c = 0; $c < $choicesPerItem; $c++) {
                    $cx = (int)($colLeft + $bubblePositions['centersMm'][$c] * $colPxPerMm);
                    $darknessValues[$c] = $this->sampleRegionDarkness(
                        $image, $cx, $cy, $bubbleRx, $bubbleRy, $width, $height
                    );
                }

                $answers[$q] = $this->pickFilledChoice($darknessValues, $choiceLabels);
            }
        }

        Log::debug('Answer detection complete', [
            'totalItems'   => $totalItems,
            'columns'      => $columns,
            'detected'     => count(array_filter($answers)),
            'bounds'       => $bounds,
            'detectedCols' => $detectedCols,
            'imgSize'      => compact('width', 'height'),
        ]);

        imagedestroy($image);
        return $answers;
    }

    /*
    |--------------------------------------------------------------------------
    | Student ID Detection
    |--------------------------------------------------------------------------
    */

    /**
     * Detect the student ID from the scannable bubble grid.
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

        imagefilter($image, IMG_FILTER_GRAYSCALE);
        imagefilter($image, IMG_FILTER_CONTRAST, -30);

        $bounds  = $this->findSheetBounds($image, $width, $height);
        $contentW = $bounds['right'] - $bounds['left'];
        $contentH = $bounds['bottom'] - $bounds['top'];
        $pxPerMm  = $bounds['pxPerMm'];
        $pxPerMmV = $bounds['pxPerMmV'];

        // Dynamically detect the ID grid header bar
        $idBounds = $this->findIdGridBounds($image, $bounds, $width, $height);

        if ($idBounds) {
            $gridLeft      = $idBounds['left'];
            $gridRight     = $idBounds['right'];
            $headerBottom  = $idBounds['headerBottom'];
        } else {
            $gridLeft      = $bounds['left'];
            $gridRight     = $bounds['left'] + (int)($contentW * 0.48);
            $headerBottom  = $bounds['top'] + (int)($contentH * 0.21);
        }

        $gridW = $gridRight - $gridLeft;

        // CSS layout: each id-digit-col is ~8.5mm wide
        $colWMm    = 8.5;
        $colWPx    = $colWMm * $pxPerMm;

        // Row layout: first bubble center 3.35mm below header, row step 6.7mm
        $firstRowOffsetMm = 3.35;
        $rowStepMm        = 6.7;

        $bubbleRx = max(3, (int)(2.2 * $pxPerMm));
        $bubbleRy = max(3, (int)(2.2 * $pxPerMmV));

        $studentId = '';

        for ($d = 0; $d < $idDigits; $d++) {
            $darknessValues = [];
            $cx = (int)($gridLeft + ($d + 0.5) * $colWPx);

            for ($n = 0; $n <= 9; $n++) {
                $cy = (int)($headerBottom + ($firstRowOffsetMm + $n * $rowStepMm) * $pxPerMmV);

                $darknessValues[$n] = $this->sampleRegionDarkness(
                    $image, $cx, $cy, $bubbleRx, $bubbleRy, $width, $height
                );
            }

            $picked = $this->pickFilledDigit($darknessValues);
            $studentId .= ($picked !== null) ? (string)$picked : '?';
        }

        imagedestroy($image);

        Log::debug('Student ID detection', [
            'raw' => $studentId,
            'clean' => str_replace('?', '', $studentId),
        ]);

        // Return the ID; '?' marks undetected digits
        return $studentId;
    }

    /**
     * Find the ID grid bounds by detecting its dark header bar.
     * Returns ['left', 'right', 'headerBottom'] or null.
     */
    private function findIdGridBounds($image, array $bounds, int $imgW, int $imgH): ?array
    {
        $contentH = $bounds['bottom'] - $bounds['top'];
        $contentW = $bounds['right'] - $bounds['left'];

        $searchTop    = $bounds['top'] + (int)($contentH * 0.15);
        $searchBottom = $bounds['top'] + (int)($contentH * 0.28);
        $searchLeft   = $bounds['left'];
        $searchRight  = $bounds['left'] + (int)($contentW * 0.55);

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
            Log::info('No ID grid header bar found');
            return null;
        }

        usort($bands, fn($a, $b) => $b['thickness'] <=> $a['thickness']);
        $darkBandStart = $bands[0]['start'];
        $darkBandEnd   = $bands[0]['end'];

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
            $gridRight = array_key_last(array_filter($profile, fn($b) => $b < 120));
        }

        $headerBottom = $darkBandEnd + 1;
        $sampleX = (int)(($gridLeft + $gridRight) / 2);
        for ($y = $darkBandEnd + 1; $y < min($searchBottom + 200, $imgH); $y++) {
            $rgb = imagecolorat($image, min($sampleX, $imgW - 1), $y);
            if (($rgb & 0xFF) > 180) {
                $headerBottom = $y;
                break;
            }
        }

        return [
            'left'         => $gridLeft,
            'right'        => $gridRight,
            'headerBottom' => $headerBottom,
        ];
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
            // Sheet spans from top-left marker to bottom-right marker
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
                'pxPerMm'        => $pxPerMm,
                'pxPerMmV'       => $pxPerMmV,
            ];
        }

        // Fallback: assume sheet fills most of the image with small margins
        Log::info('Corner markers not found, using fallback bounds');
        return [
            'left'           => (int)($width * 0.04),
            'top'            => (int)($height * 0.03),
            'right'          => (int)($width * 0.96),
            'bottom'         => (int)($height * 0.97),
            'markers_found'  => false,
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
        if ($bandStart !== null) {
            $bands[] = ['start' => $bandStart, 'end' => $bandEnd, 'thickness' => $bandEnd - $bandStart + 1];
        }

        if (empty($bands)) {
            Log::info('No column header dark band found in search region');
            return [];
        }

        // Pick the thickest dark band (the column header bar, not thin borders)
        usort($bands, fn($a, $b) => $b['thickness'] <=> $a['thickness']);
        $darkBandStart = $bands[0]['start'];
        $darkBandEnd   = $bands[0]['end'];

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
            Log::info('Column header count mismatch', [
                'found'    => count($darkRanges),
                'expected' => $expectedColumns,
                'ranges'   => $darkRanges,
            ]);
            return [];
        }

        // Step 3: Find the bottom edge of each header bar.
        // Sample multiple X positions across each header to avoid hitting
        // a gap between letters (e.g. "QUESTIONS 16 - 30") which reads bright.
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
                        // This X position transitions to light at row $y
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

        // Step 4: The detected dark header bar left edge marks the true column
        // container boundary. No left expansion needed — the firstBubbleMm offset
        // already accounts for the CSS padding + question-num width.
        // Only expand right edge slightly for anti-aliasing tolerance in gridBottom scan.
        for ($i = 0; $i < count($columns); $i++) {
            $columns[$i]['right'] += 3;
        }

        // Step 5: Find the bottom of each answer column independently.
        // Do not force a shared bottom Y; perspective/keystone distortion can make
        // outer columns sit slightly higher/lower than the center column.
        foreach ($columns as &$col) {
            $scanX = $col['left'] + (int)(($col['right'] - $col['left']) * 0.5);
            $gridBottom = $searchBottom; // fallback

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

        Log::debug('Column headers detected', [
            'columns' => $columns,
        ]);
        return $columns;
    }

    /*
    |--------------------------------------------------------------------------
    | Adaptive Bubble Selection
    |--------------------------------------------------------------------------
    */

    /**
     * Pick the filled bubble from an array of darkness readings using adaptive thresholding.
     * Compares the darkest bubble against the average of all bubbles in the row.
     * A filled bubble is significantly darker than unfilled ones.
     */
    private function pickFilledChoice(array $darknessValues, array $labels): ?string
    {
        if (empty($darknessValues)) return null;

        $minIdx = null;
        $minVal = 255;
        $total  = 0;

        foreach ($darknessValues as $i => $val) {
            $total += $val;
            if ($val < $minVal) {
                $minVal = $val;
                $minIdx = $i;
            }
        }

        $avg  = $total / count($darknessValues);
        $diff = $avg - $minVal;

        // A well-filled bubble is typically 20-80 units darker than the average.
        // Threshold of 15 is conservative enough to avoid false positives
        // while catching pencil-shaded bubbles.
        if ($diff >= 15 && $minIdx !== null && isset($labels[$minIdx])) {
            return $labels[$minIdx];
        }

        return null;
    }

    /**
     * Pick the filled digit (0-9) from an array of darkness readings.
     */
    private function pickFilledDigit(array $darknessValues): ?int
    {
        if (empty($darknessValues)) return null;

        $minIdx = null;
        $minVal = 255;
        $total  = 0;

        foreach ($darknessValues as $i => $val) {
            $total += $val;
            if ($val < $minVal) {
                $minVal = $val;
                $minIdx = $i;
            }
        }

        $avg  = $total / count($darknessValues);
        $diff = $avg - $minVal;

        if ($diff >= 15 && $minIdx !== null) {
            return $minIdx;
        }

        return null;
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
     * Expected printable width (mm) of a single answer column in the template.
     * Content width is 186mm (A4 width 210mm - 12mm left - 12mm right padding).
     * Column gap in CSS is 4mm.
     */
    private function getTemplateColumnWidthMm(int $columns): float
    {
        $contentWidthMm = 186.0;
        $gapMm = 4.0;

        if ($columns <= 1) {
            return $contentWidthMm;
        }

        return ($contentWidthMm - ($columns - 1) * $gapMm) / $columns;
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
        $firstBubbleMm = 10.5;  // shifted 1mm left for better alignment
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
     * Lower values = darker. Samples every 2nd pixel for performance.
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
     * Generate a debug overlay image showing where each bubble is being sampled.
     * Saves a PNG alongside the original image for visual verification.
     *
     * Red rectangles   = sampling regions for answer bubbles
     * Green rectangles = sampling regions for ID digit bubbles
     * Blue lines       = content bounds / grid boundaries
     *
     * @return string|null  Path to the generated debug image, or null on failure
     */
    public function generateDebugImage(string $imagePath, int $totalItems, int $choicesPerItem, int $columns = 0, int $idDigits = 7): ?string
    {
        $image = $this->loadImage($imagePath);
        if (!$image) return null;

        $width  = imagesx($image);
        $height = imagesy($image);

        // Don't apply grayscale — keep original colors for readability
        // But do load a grayscale copy for detection (same as detectAnswers)
        $grayForDebug = $this->loadImage($imagePath);
        imagefilter($grayForDebug, IMG_FILTER_GRAYSCALE);
        imagefilter($grayForDebug, IMG_FILTER_CONTRAST, -30);

        $bounds  = $this->findSheetBounds($grayForDebug, $width, $height);
        // Keep $grayForDebug alive for findColumnHeaders below

        $contentW = $bounds['right'] - $bounds['left'];
        $contentH = $bounds['bottom'] - $bounds['top'];
        $pxPerMm  = $bounds['pxPerMm'];
        $pxPerMmV = $bounds['pxPerMmV'];

        if ($columns <= 0) {
            $columns = $this->inferColumnCount($totalItems);
        }

        // Allocate colors
        $red     = imagecolorallocatealpha($image, 255, 0, 0, 60);
        $green   = imagecolorallocatealpha($image, 0, 200, 0, 60);
        $blue    = imagecolorallocatealpha($image, 0, 100, 255, 40);
        $yellow  = imagecolorallocatealpha($image, 255, 255, 0, 40);
        $redLine = imagecolorallocate($image, 255, 0, 0);
        $blueLine = imagecolorallocate($image, 0, 100, 255);
        $greenLine = imagecolorallocate($image, 0, 200, 0);

        // Draw content bounds
        imagesetthickness($image, 2);
        imagerectangle($image, $bounds['left'], $bounds['top'], $bounds['right'], $bounds['bottom'], $blueLine);

        // === Answer Grid Debug ===
        $searchTop    = $bounds['top'] + (int)($contentH * 0.48);
        $searchBottom = $bounds['top'] + (int)($contentH * 0.98);
        $itemsPerColumn = (int)ceil($totalItems / $columns);

        // Draw search region boundary
        imagerectangle($image, $bounds['left'], $searchTop, $bounds['right'], $searchBottom, $redLine);

        // Use same dynamic column header detection as detectAnswers
        $detectedCols = $this->findColumnHeaders(
            $grayForDebug, $searchTop, $searchBottom,
            $bounds['left'], $bounds['right'],
            $width, $height, $columns
        );

        // Use dynamic bubble positions based on column count
        $bubblePositions = $this->calculateBubblePositions($columns, $choicesPerItem);
        $templateColWidthMm = $this->getTemplateColumnWidthMm($columns);

        for ($col = 0; $col < $columns; $col++) {
            $startItem  = $col * $itemsPerColumn + 1;
            $endItem    = min(($col + 1) * $itemsPerColumn, $totalItems);
            $itemsInCol = $endItem - $startItem + 1;
            if ($itemsInCol <= 0) continue;

            if (isset($detectedCols[$col])) {
                $colLeft   = $detectedCols[$col]['left'];
                $colWidth  = $detectedCols[$col]['right'] - $colLeft;
                $rowsTop   = $detectedCols[$col]['headerBottom'];
                $rowsBottom = $detectedCols[$col]['gridBottom'] ?? $searchBottom;
            } else {
                $colGapPx  = (int)($contentW * 0.022);
                $calcColW  = (int)(($contentW - ($columns - 1) * $colGapPx) / $columns);
                $colLeft   = $bounds['left'] + $col * ($calcColW + $colGapPx);
                $colWidth  = $calcColW;
                $rowsTop   = $searchTop + (int)(($searchBottom - $searchTop) * 0.12);
                $rowsBottom = $searchBottom;
            }

            $rowAreaH = $rowsBottom - $rowsTop;
            $rowH     = $rowAreaH / $itemsInCol;

            $colPxPerMm = max(0.1, $colWidth / max(1.0, $templateColWidthMm));
            $bubbleRx = max(5, (int)(2.7 * $colPxPerMm));
            $bubbleRy = max(5, (int)(2.7 * $pxPerMmV));

            // Draw column boundary (detected or calculated)
            imagerectangle($image, $colLeft, $rowsTop - 5, $colLeft + $colWidth, $rowsBottom, $yellow);

            for ($row = 0; $row < $itemsInCol; $row++) {
                $cy = (int)($rowsTop + ($row + 0.5) * $rowH);

                for ($c = 0; $c < $choicesPerItem; $c++) {
                    $cx = (int)($colLeft + $bubblePositions['centersMm'][$c] * $colPxPerMm);
                    imagefilledrectangle($image,
                        $cx - $bubbleRx, $cy - $bubbleRy,
                        $cx + $bubbleRx, $cy + $bubbleRy,
                        $red
                    );
                }
            }
        }

        // === ID Grid Debug ===
        $idBounds = $this->findIdGridBounds($grayForDebug, $bounds, $width, $height);

        if ($idBounds) {
            $idGridLeft     = $idBounds['left'];
            $idGridRight    = $idBounds['right'];
            $idHeaderBottom = $idBounds['headerBottom'];
        } else {
            $idGridLeft     = $bounds['left'];
            $idGridRight    = $bounds['left'] + (int)($contentW * 0.48);
            $idHeaderBottom = $bounds['top'] + (int)($contentH * 0.21);
        }

        $idColWPx          = 8.5 * $pxPerMm;
        $idFirstRowOffMm   = 3.35;
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
                imagefilledrectangle($image,
                    $cx - $idBubbleRx, $cy - $idBubbleRy,
                    $cx + $idBubbleRx, $cy + $idBubbleRy,
                    $green
                );
            }
        }

        // Save debug image
        $debugPath = preg_replace('/\.(jpe?g|png|gif|bmp|webp)$/i', '_debug.png', $imagePath);
        imagepng($image, $debugPath);
        imagedestroy($image);
        imagedestroy($grayForDebug);

        Log::info('Debug image generated', ['path' => $debugPath]);
        return $debugPath;
    }
}


