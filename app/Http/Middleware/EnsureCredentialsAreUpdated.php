<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCredentialsAreUpdated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->must_change_credentials
            || $request->routeIs('credentials.edit', 'credentials.update', 'logout')) {
            return $next($request);
        }

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            abort(403, 'Update your credentials before using the system.');
        }

        return to_route('credentials.edit');
    }
}
