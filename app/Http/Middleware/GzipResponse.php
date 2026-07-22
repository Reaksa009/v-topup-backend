<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GzipResponse
{
    /**
     * Handle an incoming request and compress JSON/HTML response using Gzip if client supports it.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!function_exists('gzencode') || $response->headers->has('Content-Encoding')) {
            return $response;
        }

        $acceptEncoding = $request->header('Accept-Encoding', '');
        if (!str_contains($acceptEncoding, 'gzip')) {
            return $response;
        }

        $content = $response->getContent();
        if (is_string($content) && strlen($content) > 500) {
            $compressedContent = gzencode($content, 6);
            if ($compressedContent !== false) {
                $response->setContent($compressedContent);
                $response->headers->set('Content-Encoding', 'gzip');
                $response->headers->set('Content-Length', (string) strlen($compressedContent));
                $response->headers->set('Vary', 'Accept-Encoding');
            }
        }

        return $response;
    }
}
