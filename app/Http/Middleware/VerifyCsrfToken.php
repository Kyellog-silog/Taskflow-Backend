<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyCsrfToken extends Middleware
{
    protected $addHttpCookie = true;
    
    protected $except = [
        '/api/auth/login',
        '/api/auth/register',
        '/api/auth/logout',
        '/sanctum/csrf-cookie'
    ];
}
