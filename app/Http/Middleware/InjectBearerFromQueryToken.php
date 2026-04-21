<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class InjectBearerFromQueryToken
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->bearerToken() && $request->query('api_token')) {
            $request->headers->set('Authorization', 'Bearer ' . $request->query('api_token'));
        }

        return $next($request);
    }
}
