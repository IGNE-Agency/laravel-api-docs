<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Csrf;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use Igne\ApiDocs\Middleware\ExceptionsOfMiddleware;

/**
 * Documents the `X-XSRF-TOKEN` header on every operation whose CSRF token Laravel checks, a guest
 * operation included. The 419 itself is added by {@see ExceptionsOfMiddleware}.
 */
final readonly class CsrfHeader implements OperationTransformer
{
    public const string HEADER = 'X-XSRF-TOKEN';

    public function __construct(private CsrfProtection $csrf) {}

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        if (! $this->csrf->protects($routeInfo->route, $routeInfo->method)) {
            return;
        }

        $operation->addParameters([
            Parameter::make(self::HEADER, 'header')
                ->description('With the session cookie: the value of the `XSRF-TOKEN` cookie, which `GET /sanctum/csrf-cookie` sets. Not needed with a bearer token. Without a valid one the answer is 419: fetch the cookie again and retry.')
                ->setSchema(Schema::fromType(new StringType)),
        ]);
    }
}
