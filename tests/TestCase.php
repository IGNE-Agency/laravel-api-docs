<?php

declare(strict_types=1);

namespace Igne\ScrambleExtensions\Tests;

use Dedoc\Scramble\Infer\Context;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\ScrambleServiceProvider;
use Igne\ScrambleExtensions\ScrambleExtensionsServiceProvider;
use Igne\ScrambleExtensions\Tests\Fixtures\ArticleNotPublished;
use Igne\ScrambleExtensions\Tests\Fixtures\ArticleNotPublishedResponse;
use Igne\ScrambleExtensions\Tests\Fixtures\ArticlePublishedResponse;
use Igne\ScrambleExtensions\Tests\Fixtures\JsonArticlePublished;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Orchestra\Testbench\TestCase as Testbench;

/**
 * An application with Scramble, the package and the fixture routes. Nothing gives its errors a
 * shape of their own, so they are Laravel's.
 */
abstract class TestCase extends Testbench
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
        return [ScrambleServiceProvider::class, ScrambleExtensionsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // The document is read without the access check of Scramble's own middleware.
        $app['config']->set('scramble.middleware', []);

        // The session and its cookies, for the routes of the `web` group.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));

        // As a package binds its response contracts.
        $app->singleton(ArticlePublishedResponse::class, JsonArticlePublished::class);
        $app->singleton(ArticleNotPublishedResponse::class, ArticleNotPublished::class);

        // As an application excepts a path from the CSRF check in its bootstrap/app.php.
        ValidateCsrfToken::except(['api/fixtures/webhook']);
    }

    protected function defineRoutes($router): void
    {
        require __DIR__.'/Fixtures/routes.php';
    }

    /**
     * @return array<string, mixed>
     */
    protected function document(): array
    {
        return $this->getJson('/docs/api.json')->assertOk()->json();
    }
}
