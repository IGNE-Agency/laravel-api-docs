<?php

declare(strict_types=1);

namespace Igne\ScrambleExtensions;

use Dedoc\Scramble\Configuration\OperationTransformers;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the extensions the configuration turns on. Each one tells Scramble something about a
 * Laravel application it cannot read by itself; none of them decides what a response looks like.
 */
final class ScrambleExtensionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/scramble-extensions.php', 'scramble-extensions');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/scramble-extensions.php' => $this->app->configPath('scramble-extensions.php')], 'scramble-extensions-config');

        if (config('scramble-extensions.called_methods') === true) {
            Scramble::registerExtensions([ExceptionsOfCalledMethods::class]);
        }

        // In front of Scramble's own transformers, which document the error responses from what
        // the action throws.
        if (config('scramble-extensions.middleware') === true) {
            Scramble::configure()->withOperationTransformers(
                static fn (OperationTransformers $transformers): OperationTransformers => $transformers->prepend(ExceptionsOfMiddleware::class),
            );
        }

        if (config('scramble-extensions.pagination') === true) {
            Scramble::configure()->withOperationTransformers([PaginatedResponses::class]);
        }
    }
}
