<?php

declare(strict_types=1);

namespace Igne\ScrambleExtensions;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Infer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\RouteInfo;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;

/**
 * Lets Scramble see what the middleware of a route throws. Scramble only reads the action, so an
 * error a middleware answers before the action runs (a throttle, a check of the application's
 * own) is missing from the document.
 *
 * What each `handle()` throws or declares with `@throws`, as Scramble infers it, is added to what
 * the action throws: in front, in the order a request meets the middleware, and before Scramble or
 * any other extension documents the error responses. A middleware that throws through another
 * class declares it with `@throws` on `handle()`.
 */
final readonly class ExceptionsOfMiddleware implements OperationTransformer
{
    public function __construct(
        private Infer $infer,
        private Router $router,
    ) {}

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $action = $routeInfo->getActionType();

        if ($action === null) {
            return;
        }

        // An action two routes share is one type, so what an earlier route added is not added again.
        $known = array_map(static fn (Type $exception): string => $exception->toString(), $action->exceptions);
        $added = [];

        foreach ($this->thrownBy($routeInfo->route) as $exception) {
            if (! in_array($exception->toString(), $known, true)) {
                $known[] = $exception->toString();
                $added[] = $exception;
            }
        }

        $action->exceptions = [...$added, ...$action->exceptions];
    }

    /**
     * @return list<Type>
     */
    private function thrownBy(Route $route): array
    {
        return collect($this->router->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware()))
            ->filter(static fn (mixed $middleware): bool => is_string($middleware))
            ->map(static fn (string $middleware): string => Str::before($middleware, ':'))
            ->filter(static fn (string $middlewareClass): bool => method_exists($middlewareClass, 'handle'))
            ->flatMap(fn (string $middlewareClass): array => $this->infer->analyzeClass($middlewareClass)->getMethodDefinition('handle')?->type->exceptions ?? [])
            ->filter(static fn (mixed $exception): bool => $exception instanceof Type)
            ->values()
            ->all();
    }
}
