<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Middleware;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;

/**
 * The middleware a route runs, as the router resolves its aliases and groups.
 */
final readonly class RouteMiddleware
{
    /**
     * Sanctum's `statefulApi()` stack, which starts the session and checks the CSRF token.
     */
    public const string SANCTUM_STATEFUL = 'Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful';

    /**
     * The kernel is only asked for so it is built: it tells the router the middleware groups and
     * aliases, and a console command (`scramble:export`) has not built it.
     */
    public function __construct(private Router $router, Kernel $kernel) {}

    /**
     * In the order a request meets them, without parameters.
     *
     * @return list<string>
     */
    public function classesOf(Route $route): array
    {
        $classes = [];

        foreach ($this->router->gatherRouteMiddleware($route) as $middleware) {
            if (is_string($middleware)) {
                $classes[] = Str::before($middleware, ':');
            }
        }

        return $classes;
    }
}
