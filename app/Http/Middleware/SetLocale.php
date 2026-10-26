<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The language of a request: web uses the one chosen in the switcher (session),
 * the API ?lang= or Accept-Language; otherwise the app default. Only uz and ru
 * have texts; anything else would show translation keys.
 */
final class SetLocale
{
    public const LOCALES = ['uz', 'ru'];

    public const DEFAULT = 'uz';

    public const FALLBACK = 'ru';

    public function handle(Request $request, Closure $next): Response
    {
        $wanted = $request->hasSession()
            ? [$request->session()->get('locale')]
            : [$request->query('lang'), substr((string) $request->header('Accept-Language'), 0, 2)];

        foreach ($wanted as $locale) {
            if (in_array($locale, self::LOCALES, true)) {
                app()->setLocale($locale);
                break;
            }
        }

        return $next($request);
    }

    /** Keeps the app on a language that has texts even when .env says "en". */
    public static function enforceSupported(): void
    {
        if (! in_array(app()->getLocale(), self::LOCALES, true)) {
            app()->setLocale(self::DEFAULT);
        }
        if (! in_array(app()->getFallbackLocale(), self::LOCALES, true)) {
            app()->setFallbackLocale(self::FALLBACK);
        }
    }
}
