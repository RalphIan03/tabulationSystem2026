<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AccountRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureUserIsAuditor
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            $request->user()?->role === AccountRole::Auditor,
            403,
            'Only auditors can access this page.',
        );

        return $next($request);
    }
}
