<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Csrf;

use Igne\ApiDocs\Middleware\RouteMiddleware;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/**
 * Tells whether Laravel checks the CSRF token of an operation: one that changes state, on a route
 * that runs the CSRF middleware (the `web` group, or Sanctum's `statefulApi()`), on a path the
 * application does not except.
 */
final readonly class CsrfProtection
{
    /**
     * Laravel's CSRF middleware, as Laravel 13 and Laravel 12 name it.
     */
    private const array MIDDLEWARE = [
        'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
    ];

    public function __construct(private RouteMiddleware $middleware) {}

    public function protects(Route $route, string $method): bool
    {
        if (in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return false;
        }

        $middleware = $this->middlewareOf($route);

        return $middleware !== null && ! $this->isExcepted($route, $middleware);
    }

    /**
     * Whether a middleware is the CSRF check itself, or Sanctum's stateful stack that runs it.
     */
    public static function runsTheCheck(string $middlewareClass): bool
    {
        return self::isCsrfMiddleware($middlewareClass) || is_a($middlewareClass, RouteMiddleware::SANCTUM_STATEFUL, true);
    }

    /**
     * @return class-string|null
     */
    private function middlewareOf(Route $route): ?string
    {
        $running = array_find($this->middleware->classesOf($route), self::runsTheCheck(...));

        if ($running === null || self::isCsrfMiddleware($running)) {
            return $running;
        }

        // Sanctum's stateful stack runs the CSRF middleware its config names.
        $configured = config('sanctum.middleware.validate_csrf_token') ?? config('sanctum.middleware.verify_csrf_token');

        return is_string($configured) && self::isCsrfMiddleware($configured) ? $configured : null;
    }

    private static function isCsrfMiddleware(string $middlewareClass): bool
    {
        return array_any(self::MIDDLEWARE, static fn (string $csrf): bool => is_a($middlewareClass, $csrf, true));
    }

    /**
     * @param  class-string  $csrfMiddleware
     */
    private function isExcepted(Route $route, string $csrfMiddleware): bool
    {
        return array_any(
            app($csrfMiddleware)->getExcludedPaths(),
            static fn (mixed $pattern): bool => is_string($pattern)
                && Str::is($pattern === '/' ? $pattern : trim($pattern, '/'), $route->uri()),
        );
    }
}
