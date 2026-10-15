<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\EventResultsExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EventResultsDownloadController extends Controller
{
    public function __construct(private readonly EventResultsExporter $exporter) {}

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
