<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signed-in user's language (cs / en), or the panel's default. Also a
 * Livewire persistent middleware, so a component's round trip answers in it.
 */
class SetLocale
{
    public const LOCALES = ['cs' => 'Čeština', 'en' => 'English'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $locale = $user instanceof User && isset(self::LOCALES[$user->locale]) ? $user->locale : config('app.locale');
        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
