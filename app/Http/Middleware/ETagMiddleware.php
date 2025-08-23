<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ETagMiddleware
{
    /**
     * Add ETag header for GET responses with JSON content and honor If-None-Match.
     * Skips SSE endpoints and non-OK responses.
     */
    public function handle(Request $request, Closure $next)
    {
        /** @var Response $response */
        $response = $next($request);

        // Only for GET requests with 200 OK and JSON
        if ($request->isMethod('get') && $response->getStatusCode() === 200) {
            $contentType = $response->headers->get('Content-Type', '');
            $isJson = str_contains($contentType, 'application/json');

            // Skip if streaming or SSE
            $transferEncoding = $response->headers->get('Transfer-Encoding', '');
            $isStream = str_contains(strtolower($transferEncoding), 'chunked')
                || str_contains(strtolower($contentType), 'text/event-stream')
                || $response->headers->get('X-Accel-Buffering') === 'no';

            if ($isJson && !$isStream) {
                $etag = 'W/"' . sha1($response->getContent()) . '"';
                $response->headers->set('ETag', $etag);
                $ifNoneMatch = $request->headers->get('If-None-Match');
                if ($ifNoneMatch && trim($ifNoneMatch) === $etag) {
                    $response->setNotModified();
                }
            }
        }

        return $response;
    }
}
