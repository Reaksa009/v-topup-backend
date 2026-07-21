<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LogRequestCorrelation
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Extract X-Request-ID from headers or generate a new UUID
        $requestId = $request->header('X-Request-ID') ?: (string) Str::uuid();

        // Share the correlation ID with all log instances in this request
        Log::shareContext(['request_id' => $requestId]);

        $response = $next($request);

        // Add correlation ID to response headers
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
