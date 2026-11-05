<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks Amharic or English: ?lang= (remembered in the session for web pages),
 * then a supported language in Accept-Language (the app sends its current one),
 * then the signed-in user's saved preference, then the default.
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
        $locale ??= $this->fromAcceptLanguage($request);
        $locale ??= $request->user()?->locale;

        App::setLocale(in_array($locale, self::SUPPORTED, true) ? $locale : config('app.locale'));

        return $next($request);
    }

    private function fromAcceptLanguage(Request $request): ?string
    {
        foreach ($request->getLanguages() as $language) {
            $code = strtolower(substr($language, 0, 2));
            if (in_array($code, self::SUPPORTED, true)) {
                return $code;
            }
        }

        return null;
    }
}
