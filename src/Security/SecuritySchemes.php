<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Security;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\RouteInfo;
use Igne\ApiDocs\Csrf\CsrfHeader;
use Igne\ApiDocs\Middleware\RouteMiddleware;
use Illuminate\Routing\Route;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Str;

/**
 * Documents how a client authenticates on a route behind `auth`, from its guards: a Sanctum guard
 * takes a bearer token, and the session cookie too on a stateful route; a session guard takes the
 * cookie; any other guard a bearer token. A route without `auth` is public.
 */
final class SecuritySchemes implements DocumentTransformer, OperationTransformer
{
    public const string BEARER = 'bearer';

    public const string SESSION = 'session';

    /**
     * The schemes the operations of the document use.
     *
     * @var array<string, true>
     */
    private static array $used = [];

    public function __construct(private readonly RouteMiddleware $middleware) {}

    public function handle(OpenApi|Operation $target, OpenApiContext|RouteInfo $context): void
    {
        if ($target instanceof Operation && $context instanceof RouteInfo) {
            $this->secure($target, $context->route);

            return;
        }

        if ($target instanceof OpenApi) {
            [$used, self::$used] = [self::$used, []];

            if (isset($used[self::BEARER])) {
                $target->components->addSecurityScheme(
                    self::BEARER,
                    SecurityScheme::http('bearer')->setDescription('An access token, sent as `Authorization: Bearer <token>`.'),
                );
            }

            if (isset($used[self::SESSION])) {
                $cookie = (string) (config('api-docs.session_cookie') ?? config('session.cookie'));
                $target->components->addSecurityScheme(
                    self::SESSION,
                    SecurityScheme::apiKey('cookie', $cookie)->setDescription('The session cookie of a client that logged in on a cookie session.'),
                );
            }
        }
    }

    private function secure(Operation $operation, Route $route): void
    {
        $schemes = $this->schemesOf($route);

        if ($schemes === []) {
            return;
        }

        // Each scheme is a way of its own to authenticate: one of them is enough.
        $operation->security = array_map(
            static fn (string $scheme): SecurityRequirement => new SecurityRequirement([$scheme => []]),
            $schemes,
        );

        foreach ($schemes as $scheme) {
            self::$used[$scheme] = true;
        }
    }

    /**
     * @return list<string>
     */
    private function schemesOf(Route $route): array
    {
        $guards = $this->guardsOf($route);

        if ($guards === null) {
            return [];
        }

        $hasSession = array_any(
            $this->middleware->classesOf($route),
            static fn (string $class): bool => is_a($class, StartSession::class, true)
                || is_a($class, RouteMiddleware::SANCTUM_STATEFUL, true),
        );

        $schemes = [];
        foreach ($guards === [] ? [(string) config('auth.defaults.guard')] : $guards as $guard) {
            $driver = config("auth.guards.{$guard}.driver");

            if ($driver === 'session') {
                $schemes[self::SESSION] = true;

                continue;
            }

            $schemes[self::BEARER] = true;

            if ($driver === 'sanctum' && $hasSession) {
                $schemes[self::SESSION] = true;
            }
        }

        // The bearer token first, as the way every client can use.
        return array_values(array_intersect([self::BEARER, self::SESSION], array_keys($schemes)));
    }

    /**
     * An empty list for `auth` without guards, null for a route without `auth`.
     *
     * @return list<string>|null
     */
    private function guardsOf(Route $route): ?array
    {
        $guards = null;

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! Str::is(['auth', 'auth:*'], $middleware)) {
                continue;
            }

            $guards ??= [];

            if (str_contains($middleware, ':')) {
                $guards = [...$guards, ...array_filter(explode(',', Str::after($middleware, ':')))];
            }
        }

        return $guards;
    }
}
