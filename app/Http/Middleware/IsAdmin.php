<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsAdmin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && in_array($request->user()->role, ['admin', 'super-admin'])) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Forbidden. Admin privileges required.'
        ], 403);
    }
}
