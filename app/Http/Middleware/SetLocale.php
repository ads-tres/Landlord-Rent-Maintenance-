<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks Amharic or English: ?lang= (remembered in the session for web pages),
 * then the signed-in user's preference, then Accept-Language, then the default.
 */
class SetLocale
{
    public const SUPPORTED = ['am', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->query('lang');

        if (in_array($locale, self::SUPPORTED, true) && $request->hasSession()) {
            $request->session()->put('locale', $locale);
        }

        $locale = in_array($locale, self::SUPPORTED, true) ? $locale : null;
        $locale ??= $request->hasSession() ? $request->session()->get('locale') : null;
        $locale ??= $request->user()?->locale;
        $locale ??= $request->getPreferredLanguage(self::SUPPORTED);

        App::setLocale(in_array($locale, self::SUPPORTED, true) ? $locale : config('app.locale'));

        return $next($request);
    }
}
