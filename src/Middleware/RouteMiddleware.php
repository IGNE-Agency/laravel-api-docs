<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Middleware;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;

/**
 * The middleware a route runs, as the router resolves its aliases and groups.
 */
final readonly class RouteMiddleware
{
    /**
     * Sanctum's stateful stack (`statefulApi()`), which starts the session and checks the CSRF token.
     */
    public const string SANCTUM_STATEFUL = 'Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful';

    public function __construct(private Router $router) {}

    /**
     * The class of each middleware of the route, in the order a request meets them, without parameters.
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
