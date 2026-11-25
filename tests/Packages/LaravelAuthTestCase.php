<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Tests\Packages;

use Dedoc\Scramble\Infer\Context;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\ScrambleServiceProvider;
use Igne\ApiDocs\ApiDocsServiceProvider;
use Igne\Auth\AuthenticationServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Laravel\Fortify\Features;
use Laravel\Fortify\FortifyServiceProvider;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Laravel\Sanctum\SanctumServiceProvider;
use Orchestra\Testbench\TestCase as Testbench;
use ReflectionClass;

/**
 * An application that installed igne-agency/laravel-auth next to this package. That package holds
 * no documentation code and knows nothing of this one. This side is where the two are tested
 * together: a real package, with routes of its own and routes that point at Laravel Fortify.
 */
abstract class LaravelAuthTestCase extends Testbench
{
    protected function setUp(): void
    {
        // Scramble keeps its extensions in statics, which would carry them from one test to the next.
        Scramble::$extensions = [];
        Context::reset();

        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [ScrambleServiceProvider::class, ApiDocsServiceProvider::class, AuthenticationServiceProvider::class, FortifyServiceProvider::class, SanctumServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'auth.providers.users.model' => User::class,
            'cache.default' => 'array',
            'database.default' => 'testing',
            'fortify.views' => false,
            'fortify.limiters.login' => null,
            'fortify.features' => [Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true])],
            'session.driver' => 'array',
            'sanctum.stateful' => ['localhost'],
            // The document is read without the access check of Scramble's own middleware.
            'scramble.middleware' => [],
        ]);

        // As an application does with `$middleware->statefulApi()`.
        $app->make(Kernel::class)->prependMiddlewareToGroup('api', EnsureFrontendRequestsAreStateful::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();

        foreach ([FortifyServiceProvider::class, SanctumServiceProvider::class, AuthenticationServiceProvider::class] as $provider) {
            $this->loadMigrationsFrom(dirname((string) (new ReflectionClass($provider))->getFileName(), 2).'/database/migrations');
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function document(): array
    {
        return $this->getJson('/docs/api.json')->assertOk()->json();
    }
}
