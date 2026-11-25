<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Csrf;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;

/**
 * Tells whether Laravel checks the CSRF token of an operation: a request that is not a GET, HEAD or
 * OPTIONS, on a route that runs the CSRF middleware directly (the `web` group) or through Sanctum's
 * stateful stack (`statefulApi()`), on a path the application does not except from the check.
 */
final readonly class CsrfProtection
{
    /**
     * Laravel's CSRF middleware by the name of each version.
     */
    private const array MIDDLEWARE = [
        'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
    ];

    private const string SANCTUM_STATEFUL = 'Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful';

    public function __construct(private Router $router) {}

    public function protects(Route $route, string $method): bool
    {
        if (in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return false;
        }

        $middleware = $this->middlewareOf($route);

        return $middleware !== null && ! $this->isExcepted($route, $middleware);
    }

    /**
     * Whether a middleware of a route is the CSRF check itself, or the stack of Sanctum that runs it.
     */
    public static function runsTheCheck(string $middlewareClass): bool
    {
        return self::isCsrfMiddleware($middlewareClass) || is_a($middlewareClass, self::SANCTUM_STATEFUL, true);
    }

    /**
     * The CSRF middleware the route runs: its own, or the one Sanctum's stateful stack is configured with.
     *
     * @return class-string|null
     */
    private function middlewareOf(Route $route): ?string
    {
        foreach ($this->router->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware()) as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            $middlewareClass = Str::before($middleware, ':');

            if (self::isCsrfMiddleware($middlewareClass)) {
                return $middlewareClass;
            }

            if (is_a($middlewareClass, self::SANCTUM_STATEFUL, true)) {
                $configured = config('sanctum.middleware.validate_csrf_token') ?? config('sanctum.middleware.verify_csrf_token');

                return is_string($configured) && self::isCsrfMiddleware($configured) ? $configured : null;
            }
        }

        return null;
    }

    private static function isCsrfMiddleware(string $middlewareClass): bool
    {
        foreach (self::MIDDLEWARE as $csrfMiddleware) {
            if (is_a($middlewareClass, $csrfMiddleware, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the application excepts the path of the route from the check, as Laravel matches it.
     *
     * @param  class-string  $csrfMiddleware
     */
    private function isExcepted(Route $route, string $csrfMiddleware): bool
    {
        $instance = app($csrfMiddleware);

        foreach (method_exists($instance, 'getExcludedPaths') ? $instance->getExcludedPaths() : [] as $pattern) {
            if (is_string($pattern) && Str::is($pattern === '/' ? $pattern : trim($pattern, '/'), $route->uri())) {
                return true;
            }
        }

        return false;
    }
}
