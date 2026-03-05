<?php

namespace App\Jobs;

use App\Models\Exam;
use App\Services\OMRService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessBubbleSheetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Exam $exam)
    {
        $this->timeout = 300;
    }

    public function handle(OMRService $omrService): void
    {
        $omrService->processSheet($this->exam);
    }
}