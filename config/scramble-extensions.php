<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Errors of called methods
    |--------------------------------------------------------------------------
    |
    | Scramble documents the exceptions an action throws itself. When true, it
    | also follows the calls the action makes into the application's own code
    | (a service, a repository), and the calls a package makes inside itself,
    | and documents what those methods throw.
    |
    */

    'called_methods' => true,

    /*
    |--------------------------------------------------------------------------
    | Packages with routes
    |--------------------------------------------------------------------------
    |
    | Scramble reads the classes under vendor/ by their signatures only. When
    | true, a package that has a controller on a route is read like the
    | application: the source of its classes is parsed, so its Resources
    | keep the types of the values they take from other classes of the
    | package.
    |
    */

    'packages' => true,

    /*
    |--------------------------------------------------------------------------
    | Bound responses
    |--------------------------------------------------------------------------
    |
    | A controller of a package such as Laravel Fortify answers through a
    | response contract the container resolves: return app(LoginResponse::class).
    | When true, the contract is followed to the class that is bound to it,
    | the package's own or one put in its place, and what that class answers
    | a JSON client is documented.
    |
    */

    'bound_responses' => true,

    /*
    |--------------------------------------------------------------------------
    | Errors of middleware
    |--------------------------------------------------------------------------
    |
    | When true, what the middleware of a route throws is documented as an
    | error of the endpoint: a throttle, the CSRF check, a check of the
    | application or of a package. A middleware on a whole group that only
    | refuses some routes tells which with a static throwsFor(Route $route).
    |
    */

    'middleware' => true,

    /*
    |--------------------------------------------------------------------------
    | Paginated responses
    |--------------------------------------------------------------------------
    |
    | When true, a paginated list is documented with named schemas
    | (PaginationLinks, PaginationMeta, Paginated<Resource>) instead of one
    | inline schema per endpoint.
    |
    */

    'pagination' => true,

    /*
    |--------------------------------------------------------------------------
    | CSRF header
    |--------------------------------------------------------------------------
    |
    | When true, every operation whose CSRF token Laravel checks takes the
    | X-XSRF-TOKEN header: one that changes state on a route of the "web"
    | group or of Sanctum's stateful API, except on the paths the application
    | excepts from the check.
    |
    */

    'csrf' => true,

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | When true, a route behind the "auth" middleware documents how a client
    | authenticates, from the guards of that middleware: a bearer token, the
    | session cookie, or either. "session_cookie" names the cookie in the
    | document; null uses "session.cookie", which usually derives from
    | APP_NAME. Pin it when the document is committed, so every environment
    | generates the same one.
    |
    */

    'security' => true,

    'session_cookie' => null,

    /*
    |--------------------------------------------------------------------------
    | Error responses
    |--------------------------------------------------------------------------
    |
    | When enabled, the error responses are documented as the application
    | really answers them: every error an operation can throw is rendered by
    | the application's own exception handler and the answer is written down.
    | Nothing describes the shape of an error to this package, so Laravel's
    | default errors and any error format are documented the same way.
    |
    | Each error gets a named schema, "suffix" after its name
    | (TwoFactorRequiredError). "set" and "codes" name the closed set of every
    | error and of the values that tell them apart. A member named in
    | "free_text" is text for a person and never a fixed value.
    |
    */

    'errors' => [
        'enabled' => true,
        'suffix' => 'Error',
        'set' => 'ApiError',
        'codes' => 'ApiErrorCode',
        'free_text' => ['message', 'detail', 'title', 'description'],
    ],

];
