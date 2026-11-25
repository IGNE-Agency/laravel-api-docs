<?php

declare(strict_types=1);

namespace Igne\ScrambleExtensions\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * A middleware on a whole group that only refuses some of its routes, and tells which. It
 * implements nothing: `throwsFor()` is all a package needs to declare.
 */
final class MembersOnly
{
    /**
     * @return list<class-string>
     */
    public static function throwsFor(Route $route): array
    {
        return str_ends_with((string) $route->getName(), '.members') ? [ArticleLockedException::class] : [];
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (str_ends_with((string) $request->route()?->getName(), '.members') && $request->user() === null) {
            throw new ArticleLockedException;
        }

        return $next($request);
    }
}
