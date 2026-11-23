<?php

declare(strict_types=1);

namespace Igne\ScrambleExtensions\Tests;

use Dedoc\Scramble\Infer\Context;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\ScrambleServiceProvider;
use Igne\ScrambleExtensions\ScrambleExtensionsServiceProvider;
use Orchestra\Testbench\TestCase as Testbench;

/**
 * An application with Scramble, the package and the fixture routes. The errors are documented as
 * Scramble documents them: nothing here changes what a response looks like.
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
