<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\App;
class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        // Read the language the frontend asked for
        $locale = $request->header('Accept-Language');

        // Check if we support it, default to English
        $supportedLocales = ['en', 'fr', 'ar'];

        if ($locale && in_array($locale, $supportedLocales)) {
            App::setLocale($locale);
        } else {
            App::setLocale('en'); // Default fallback
        }

        return $next($request);
    }
}
