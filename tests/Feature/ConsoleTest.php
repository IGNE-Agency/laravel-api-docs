<?php

declare(strict_types=1);

use Igne\ApiDocs\Middleware\RouteMiddleware;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

// `php artisan scramble:export` and `scramble:cache` generate the document without a request. Nothing has built
// the HTTP kernel then, and it is the kernel that tells the router what `api` or `throttle` stand for.

it('reads the middleware of a route when no request has built the HTTP kernel yet', function () {
    app()->forgetInstance(Kernel::class);
    app('router')->flushMiddlewareGroups();

    $route = app('router')->getRoutes()->match(Request::create('/api/fixtures/pings/1'));

    expect(app(RouteMiddleware::class)->classesOf($route))->toContain(SubstituteBindings::class);
});
