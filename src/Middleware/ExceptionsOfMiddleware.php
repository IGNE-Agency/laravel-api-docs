<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Middleware;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Infer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\RouteInfo;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Igne\ApiDocs\Csrf\CsrfProtection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route;
use Illuminate\Session\TokenMismatchException;
use WeakMap;

/**
 * Adds what the middleware of a route throws to the errors of its operation: Scramble only reads
 * the action.
 *
 * - A middleware with `public static function throwsFor(Route $route): array` names its errors per
 *   route. It is duck-typed, so a package declares it without depending on this one.
 * - Any other middleware: what its `handle()` throws or declares with `@throws`.
 * - The CSRF check (419) and the route model binding (404) are stated here, only where they apply.
 *
 * Kept per route: Scramble holds one type per controller method, so two routes that share an
 * action would otherwise document each other's middleware.
 */
final class ExceptionsOfMiddleware implements OperationTransformer
{
    /**
     * What each action throws by itself, before any route added its middleware.
     *
     * @var WeakMap<object, list<Type>>|null
     */
    private static ?WeakMap $ownExceptions = null;

    public function __construct(
        private readonly Infer $infer,
        private readonly RouteMiddleware $middleware,
        private readonly CsrfProtection $csrf,
    ) {}

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $action = $routeInfo->getActionType();

        if ($action === null) {
            return;
        }

        self::$ownExceptions ??= new WeakMap;
        self::$ownExceptions[$action] ??= $action->exceptions;

        $exceptions = [];
        foreach ([...$this->thrownBy($routeInfo->route, $routeInfo->method), ...self::$ownExceptions[$action]] as $exception) {
            $exceptions[$exception->toString()] ??= $exception;
        }

        $action->exceptions = array_values($exceptions);
    }

    /**
     * @return list<Type>
     */
    private function thrownBy(Route $route, string $method): array
    {
        $thrown = [];

        foreach ($this->middleware->classesOf($route) as $middlewareClass) {
            $thrown = [...$thrown, ...$this->thrownByMiddleware($middlewareClass, $route, $method)];
        }

        return $thrown;
    }

    /**
     * @return list<Type>
     */
    private function thrownByMiddleware(string $middlewareClass, Route $route, string $method): array
    {
        if (CsrfProtection::runsTheCheck($middlewareClass)) {
            return $this->csrf->protects($route, $method) ? [new ObjectType(TokenMismatchException::class)] : [];
        }

        if (is_a($middlewareClass, SubstituteBindings::class, true)) {
            // Not read from the middleware: Laravel declares it with `@throws` only since version 13.
            return $route->parameterNames() === [] ? [] : [new ObjectType(ModelNotFoundException::class)];
        }

        if (method_exists($middlewareClass, 'throwsFor')) {
            return array_map(
                static fn (string $exceptionClass): Type => new ObjectType($exceptionClass),
                array_values(array_filter((array) $middlewareClass::throwsFor($route), is_string(...))),
            );
        }

        if (! method_exists($middlewareClass, 'handle')) {
            return [];
        }

        return array_values($this->infer->analyzeClass($middlewareClass)->getMethodDefinition('handle')?->type->exceptions ?? []);
    }
}
