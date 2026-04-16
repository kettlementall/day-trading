<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MarkStateful
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('sanctum', true);

        return $next($request);
    }
}
