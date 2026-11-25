<?php

declare(strict_types=1);

namespace Igne\ScrambleExtensions;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Str;

/**
 * Documents how a client authenticates on a route behind the `auth` middleware, from the guards of
 * that middleware:
 *
 * - a guard of Sanctum takes a bearer token, and also the session cookie when the route runs
 *   Sanctum's stateful stack (`statefulApi()`): the two ways an SPA and any other client log in;
 * - a session guard takes the session cookie;
 * - any other guard takes a bearer token.
 *
 * A route without `auth` is public. The XSRF header a session client sends is no way to
 * authenticate, so it is no part of this: {@see CsrfHeader} documents it.
 */
final class Security implements DocumentTransformer, OperationTransformer
{
    public const string BEARER = 'bearer';

    public const string SESSION = 'session';

    private const string SANCTUM_STATEFUL = 'Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful';

    /**
     * The schemes the operations of the document use.
     *
     * @var array<string, true>
     */
    private static array $used = [];

    public function __construct(private readonly Router $router) {}

    public function handle(OpenApi|Operation $target, OpenApiContext|RouteInfo $context): void
    {
        if ($target instanceof Operation && $context instanceof RouteInfo) {
            $this->secure($target, $context->route);

            return;
        }

        if ($target instanceof OpenApi) {
            [$used, self::$used] = [self::$used, []];

            if (isset($used[self::BEARER])) {
                $target->components->addSecurityScheme(self::BEARER, SecurityScheme::http('bearer')->setDescription('An access token, sent as `Authorization: Bearer <token>`.'));
            }

            if (isset($used[self::SESSION])) {
                $cookie = config('scramble-extensions.session_cookie') ?? config('session.cookie');
                $target->components->addSecurityScheme(self::SESSION, SecurityScheme::apiKey('cookie', (string) $cookie)->setDescription('The session cookie of a client that logged in on a cookie session.'));
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
        $operation->security = array_map(static fn (string $scheme): SecurityRequirement => new SecurityRequirement([$scheme => []]), $schemes);

        foreach ($schemes as $scheme) {
            self::$used[$scheme] = true;
        }
    }

    /**
     * @return list<string>
     */
    private function schemesOf(Route $route): array
    {
        $guards = null;
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && Str::is(['auth', 'auth:*'], $middleware)) {
                $guards = [...($guards ?? []), ...array_filter(explode(',', Str::after($middleware, ':') === $middleware ? '' : Str::after($middleware, ':')))];
            }
        }

        if ($guards === null) {
            return [];
        }

        $resolved = array_map(static fn (mixed $middleware): string => is_string($middleware) ? Str::before($middleware, ':') : '', $this->router->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware()));
        $hasSession = collect($resolved)->contains(static fn (string $class): bool => $class !== '' && (is_a($class, StartSession::class, true) || is_a($class, self::SANCTUM_STATEFUL, true)));

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
        return array_values(array_filter([isset($schemes[self::BEARER]) ? self::BEARER : null, isset($schemes[self::SESSION]) ? self::SESSION : null]));
    }
}
