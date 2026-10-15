<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Event;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureEventIsVisibleToAuditor
{
    public function handle(Request $request, Closure $next): Response
    {
        $event = $request->route('event');

        if ($event instanceof Event
            && (! $event->is_active || $event->isArchived())
            && ! $request->user()?->isAdmin()) {
            abort(404);
        }

        return $next($request);
    }
}
