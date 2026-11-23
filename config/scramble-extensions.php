<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Exceptions of called methods
    |--------------------------------------------------------------------------
    |
    | Scramble documents the exceptions an action throws itself. When true, it
    | also follows the calls the action makes into the application's own code
    | (a service, a repository) and documents what those methods throw.
    |
    */

    'called_methods' => true,

    /*
    |--------------------------------------------------------------------------
    | Exceptions of middleware
    |--------------------------------------------------------------------------
    |
    | When true, what the middleware of a route throws (a throttle, a check of
    | the application's own) is documented as an error of the endpoint.
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

];
