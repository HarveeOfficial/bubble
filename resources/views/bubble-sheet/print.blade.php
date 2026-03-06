<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} - Bubble Sheet</title>
    <style>
        /* ===== Reset & Base ===== */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Arial', 'Helvetica Neue', sans-serif;
            font-size: 11px;
            color: #000;
            background: #fff;
        }

        /* ===== Page Setup ===== */
        .sheet-page {
            width: 210mm;
            min-height: 297mm;
            padding: 10mm 12mm;
            margin: 0 auto;
            position: relative;
            page-break-after: always;
        }
        .sheet-page:last-child {
            page-break-after: auto;
        }

        /* ===== Alignment Markers ===== */
        .corner-marker {
            position: absolute;
            width: 12mm;
            height: 12mm;
            border: 2.5px solid #000;
        }
        .corner-marker.top-left     { top: 10mm;  left: 5mm;  border-right: none; border-bottom: none; }
        .corner-marker.top-right    { top: 10mm;  right: 5mm; border-left: none;  border-bottom: none; }
        .corner-marker.bottom-left  { bottom: 5mm; left: 5mm; border-right: none; border-top: none; }
        .corner-marker.bottom-right { bottom: 5mm; right: 5mm; border-left: none; border-top: none; }

        /* Filled square markers for better scan detection */
        .corner-marker::after {
            content: '';
            position: absolute;
            width: 4mm;
            height: 4mm;
            background: #000;
        }
        .corner-marker.top-left::after     { top: 0; left: 0; }
        .corner-marker.top-right::after    { top: 0; right: 0; }
        .corner-marker.bottom-left::after  { bottom: 0; left: 0; }
        .corner-marker.bottom-right::after { bottom: 0; right: 0; }

        /* ===== Header ===== */
        .sheet-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 4mm;
            margin-bottom: 4mm;
            margin-top: 8mm;
        }
        .sheet-header h1 {
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .sheet-header .subtitle {
            font-size: 12px;
            color: #333;
            margin-top: 2px;
        }

        /* ===== Student Info ===== */
        .student-info {
            display: flex;
            gap: 6mm;
            margin-bottom: 4mm;
            padding-bottom: 3mm;
            border-bottom: 1px solid #ccc;
        }
        .student-info .field {
            flex: 1;
        }
        .student-info .field label {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            display: block;
            margin-bottom: 1mm;
        }
        .student-info .field .line {
            border-bottom: 1.5px solid #000;
            height: 7mm;
            width: 100%;
        }

        /* ===== Student ID Bubble Grid ===== */
        .id-grid-section {
            margin-bottom: 4mm;
            padding-bottom: 3mm;
            border-bottom: 1px solid #ccc;
        }
        .id-grid-section .section-label {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 2mm;
        }
        .id-grid-wrapper {
            display: flex;
            gap: 0;
        }
        .id-digit-col {
            display: flex;
            flex-direction: column;
            align-items: center;
            border: 1px solid #ccc;
            border-right: none;
        }
        .id-digit-col:last-child {
            border-right: 1px solid #ccc;
        }
        .id-digit-col .digit-header {
            background: #222;
            color: #fff;
            width: 100%;
            text-align: center;
            padding: 1mm 2mm;
            font-size: 8px;
            font-weight: 600;
        }
        .id-digit-col .id-bubble {
            width: 5.5mm;
            height: 5.5mm;
            border: 1.5px solid #000;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 7px;
            font-weight: 600;
            color: #555;
            background: #fff;
            margin: 0.6mm 1.5mm;
        }

        /* ===== Instructions ===== */
        .sheet-instructions {
            background: #f8f8f8;
            border: 1px solid #ddd;
            border-radius: 3px;
            padding: 2.5mm 4mm;
            margin-bottom: 4mm;
            font-size: 9px;
            color: #444;
            line-height: 1.5;
        }
        .sheet-instructions strong {
            color: #000;
        }

        /* ===== Bubble Grid ===== */
        .bubble-grid {
            display: flex;
            gap: 4mm;
        }
        .bubble-column {
            flex: 1;
            border: 1px solid #ccc;
            border-radius: 3px;
            overflow: hidden;
        }
        .bubble-column .col-header {
            background: #222;
            color: #fff;
            text-align: center;
            padding: 1.5mm 0;
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ===== Question Row ===== */
        .question-row {
            display: flex;
            align-items: center;
            padding: {{ $totalItems > 30 ? '0.6mm' : '1mm' }} 2.5mm;
            border-bottom: 1px solid #eee;
        }
        .question-row:last-child {
            border-bottom: none;
        }
        .question-row:nth-child(even) {
            background: #fafafa;
        }
        .question-row:nth-child(odd) {
            background: #fff;
        }
        /* Highlight every 5th question for easy reading */
        .question-row.highlight {
            background: #f0f0f0;
        }
        .question-num {
            width: {{ $totalItems > 30 ? '5.5mm' : '7mm' }};
            text-align: right;
            font-weight: 700;
            font-size: {{ $totalItems > 30 ? '8px' : '10px' }};
            padding-right: {{ $totalItems > 30 ? '1.5mm' : '2mm' }};
            color: #333;
            flex-shrink: 0;
        }
        .bubbles {
            display: flex;
            gap: {{ $totalItems > 30 ? '1.5mm' : '2mm' }};
            align-items: center;
        }

        /* ===== Individual Bubble ===== */
        .bubble {
            width: {{ $totalItems > 30 ? '4.5mm' : '6mm' }};
            height: {{ $totalItems > 30 ? '4.5mm' : '6mm' }};
            border: 1.5px solid #000;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: {{ $totalItems > 30 ? '6.5px' : '8px' }};
            font-weight: 600;
            color: #555;
            position: relative;
            background: #fff;
        }

        /* ===== Footer ===== */
        .sheet-footer {
            margin-top: 4mm;
            padding-top: 2mm;
            border-top: 1px solid #ccc;
            display: flex;
            justify-content: space-between;
            font-size: 8px;
            color: #999;
        }

        /* ===== No-Print Controls ===== */
        .no-print {
            text-align: center;
            padding: 15px;
            background: #4f46e5;
            color: #fff;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .no-print button {
            background: #fff;
            color: #4f46e5;
            border: none;
            padding: 8px 24px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            margin: 0 6px;
        }
        .no-print button:hover {
            background: #e0e7ff;
        }
        .no-print a {
            color: #c7d2fe;
            font-size: 13px;
            margin-left: 12px;
        }

        /* ===== Print Styles ===== */
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .sheet-page {
                padding: 8mm 10mm;
                margin: 0;
                width: 100%;
                min-height: auto;
            }
            @page {
                size: A4 portrait;
                margin: 0;
            }
        }

        /* ===== Screen Preview ===== */
        @media screen {
            body { background: #e5e7eb; }
            .sheet-page {
                box-shadow: 0 2px 12px rgba(0,0,0,0.15);
                margin-top: 12px;
                margin-bottom: 12px;
                border-radius: 2px;
            }
        }
    </style>
</head>
<body>
    {{-- Print Controls --}}
    <div class="no-print">
        <button onclick="window.print()">&#128424; Print Bubble Sheet</button>
        <a href="{{ route('bubble-sheet.template-form') }}">&#8592; Back to settings</a>
    </div>

    @php
        $choiceLabels = ['A', 'B', 'C', 'D', 'E'];
        $itemsPerColumn = (int)ceil($totalItems / $columns);
    @endphp

    @for($copy = 1; $copy <= $copies; $copy++)
    <div class="sheet-page">
        {{-- Alignment corner markers --}}
        <div class="corner-marker top-left"></div>
        <div class="corner-marker top-right"></div>
        <div class="corner-marker bottom-left"></div>
        <div class="corner-marker bottom-right"></div>

        {{-- Header --}}
        <div class="sheet-header">
            <h1>{{ $title }}</h1>
            @if($subject)
                <div class="subtitle">{{ $subject }}</div>
            @endif
            <div class="subtitle" style="margin-top: 1mm; font-size: 10px; color: #666;">
                {{ $totalItems }} Questions &bull; {{ $choicesPerItem }} Choices (A-{{ $choiceLabels[$choicesPerItem - 1] }})
            </div>
        </div>

        {{-- Student Info Fields --}}
        <div class="student-info">
            <div class="field">
                <label>Student Name</label>
                <div class="line"></div>
            </div>
            <div class="field" style="flex: 0.5;">
                <label>Date</label>
                <div class="line"></div>
            </div>
            <div class="field" style="flex: 0.4;">
                <label>Section</label>
                <div class="line"></div>
            </div>
        </div>

        {{-- Student ID Bubble Grid (machine-readable) --}}
        <div class="id-grid-section" style="margin-left: 8mm;">
            <div class="section-label">Student ID (shade one digit per column)</div>
            <div class="id-grid-wrapper">
            @for($d = 0; $d < $idDigits; $d++)
                <div class="id-digit-col">
                <div class="digit-header">{{ $d + 1 }}</div>
                @for($n = 0; $n <= 9; $n++)
                    <div class="id-bubble">{{ $n }}</div>
                @endfor
                </div>
                @endfor
            </div>
        </div>

        {{-- Instructions --}}
        <div class="sheet-instructions">
            <strong>Instructions:</strong>
            1) Shade your Student ID in the grid above &mdash; one digit per column.
            2) Shade the circle corresponding to your answer completely using a dark pen or pencil.
            Erase changes cleanly. Do not make stray marks on this sheet.
            Example: &#9899; = correct &nbsp;|&nbsp; &#9675; = incorrect
        </div>

        {{-- Bubble Grid --}}
        <div class="bubble-grid">
            @for($col = 0; $col < $columns; $col++)
                @php
                    $startItem = $col * $itemsPerColumn + 1;
                    $endItem = min(($col + 1) * $itemsPerColumn, $totalItems);
                @endphp
                <div class="bubble-column">
                    <div class="col-header">
                        @if($columns > 1)
                            Questions {{ $startItem }} - {{ $endItem }}
                        @else
                            Questions
                        @endif
                    </div>
                    @for($q = $startItem; $q <= $endItem; $q++)
                        <div class="question-row {{ $q % 5 === 0 ? 'highlight' : '' }}">
                            <div class="question-num">{{ $q }}</div>
                            <div class="bubbles">
                                @for($c = 0; $c < $choicesPerItem; $c++)
                                    <div class="bubble">{{ $choiceLabels[$c] }}</div>
                                @endfor
                            </div>
                        </div>
                    @endfor
                </div>
            @endfor
        </div>

        {{-- Footer --}}
        <div class="sheet-footer">
            <span>OMR Sheet &bull; Generated {{ now()->format('M d, Y') }}</span>
            @if($copies > 1)
                <span>Copy {{ $copy }} of {{ $copies }}</span>
            @endif
            <span>{{ $totalItems }} items &bull; {{ $choicesPerItem }} choices</span>
        </div>
    </div>
    @endfor
</body>
</html>
