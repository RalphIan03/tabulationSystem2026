<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\EventResultsExporter;
use App\Services\OverallResultsExporter;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EventResultsDownloadController extends Controller
{
    public function __construct(
        private readonly EventResultsExporter $exporter,
        private readonly OverallResultsExporter $overallExporter,
    ) {}

    public function export(Event $event, string $format): Response
    {
        $report = $this->overallExporter->report($event);
        $content = $format === 'pdf' ? $this->overallExporter->pdf($report) : $this->overallExporter->xlsx($report);

        return response($content, 200, [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$this->overallExporter->filename($event, $format).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function download(Event $event): StreamedResponse
    {
        return response()->streamDownload(
            fn () => $this->exporter->write(fopen('php://output', 'wb'), $event),
            $this->exporter->filename($event),
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
