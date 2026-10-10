<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Competition;
use App\Models\Event;
use App\Services\CompetitionScoreExporter;
use Illuminate\Http\Response;

final class CompetitionScoreExportController extends Controller
{
    public function __construct(private readonly CompetitionScoreExporter $exporter) {}

    public function download(Event $event, Category $category, Competition $competition, string $format): Response
    {
        $report = $this->exporter->report($event, $category, $competition);
        $content = $format === 'pdf'
            ? $this->exporter->pdf($report)
            : $this->exporter->xlsx($report);
        $filename = $this->exporter->filename($event, $competition, $format);

        return response($content, 200, [
            'Content-Type' => $format === 'pdf'
                ? 'application/pdf'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
