<?php

namespace App\Shared\Http\Middleware;

use App\Shared\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->status === UserStatus::Active, 403, 'Ce compte n’est pas actif.');

        return $next($request);
    }
}
