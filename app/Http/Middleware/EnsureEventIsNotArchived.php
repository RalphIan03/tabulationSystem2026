<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Event;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureEventIsNotArchived
{
    public function handle(Request $request, Closure $next): Response
    {
        $event = $request->route('event');
        $downloadRoutes = [
            'events.show',
            'events.results.download',
            'events.categories.competitions.scores.export',
        ];

        if ($event instanceof Event
            && $event->isArchived()
            && ! in_array($request->route()?->getName(), $downloadRoutes, true)) {
            abort(403, 'Archived events are read-only.');
        }

        return $next($request);
    }
}
