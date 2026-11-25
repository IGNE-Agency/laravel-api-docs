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
 * Lets Scramble see what the middleware of a route throws. Scramble only reads the action, so an
 * error a middleware answers before the action runs (a throttle, a check of the application's own
 * or of a package) is missing from the document.
 *
 * What each middleware throws is put in front of what the action throws, in the order a request
 * meets the middleware, before Scramble or anything else documents the error responses:
 *
 * - a middleware that sits on a whole group but only refuses some routes tells which errors a
 *   route can get, with `public static function throwsFor(Route $route): array`. There is no
 *   interface to implement: a package declares it without depending on this one;
 * - of any other middleware, what its `handle()` throws or declares with `@throws`;
 * - the CSRF check answers 419, but only an operation that changes state on a path that is not
 *   excepted ({@see CsrfProtection});
 * - the route model binding answers 404, but only a route that has a parameter to bind.
 *
 * The errors are kept per route. Scramble holds one type per controller method, so two routes that
 * share an action would otherwise document each other's middleware.
 */
final class ExceptionsOfMiddleware implements OperationTransformer
{
    /**
     * What each action throws by itself, as it was before any route added its middleware.
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
            // Stated here and not read from the middleware: Laravel declares it with `@throws` only since version 13.
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
