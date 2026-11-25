<?php

declare(strict_types=1);

namespace Igne\ScrambleExtensions;

use Dedoc\Scramble\Configuration\OperationTransformers;
use Dedoc\Scramble\Scramble;
use Igne\ScrambleExtensions\Errors\AppAnswers;
use Igne\ScrambleExtensions\Errors\ErrorSchemas;
use Igne\ScrambleExtensions\Errors\ErrorSet;
use Igne\ScrambleExtensions\Errors\RenderedErrors;
use Igne\ScrambleExtensions\Errors\SchemaFromSample;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the rules the configuration turns on. Each one tells Scramble something about a Laravel
 * application it cannot read by itself, and applies to every route of the document, whoever
 * registered it: the application or a package.
 *
 * The rules run in two phases, so the result does not depend on the order the packages of an
 * application boot in:
 *
 * - a rule that adds knowledge (what an operation can throw) runs in front of Scramble's own
 *   transformers;
 * - a rule that writes a part of the document (the errors, the security, a header) runs once every
 *   provider has booted, after the transformers of the application and of every other package, and
 *   each writes a part of its own.
 */
final class ScrambleExtensionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/scramble-extensions.php', 'scramble-extensions');

        $this->app->singleton(AppAnswers::class);
        $this->app->singleton(ErrorSchemas::class, static fn (): ErrorSchemas => new ErrorSchemas((string) config('scramble-extensions.errors.suffix', 'Error')));
        $this->app->bind(SchemaFromSample::class, static fn (): SchemaFromSample => new SchemaFromSample(array_values(array_filter((array) config('scramble-extensions.errors.free_text', []), is_string(...)))));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/scramble-extensions.php' => $this->app->configPath('scramble-extensions.php')], 'scramble-extensions-config');

        if (config('scramble-extensions.called_methods') === true) {
            Scramble::registerExtensions([ExceptionsOfCalledMethods::class]);
        }

        if (config('scramble-extensions.middleware') === true) {
            Scramble::configure()->withOperationTransformers(
                static fn (OperationTransformers $transformers): OperationTransformers => $transformers->prepend(ExceptionsOfMiddleware::class),
            );
        }

        if (config('scramble-extensions.pagination') === true) {
            Scramble::configure()->withOperationTransformers([PaginatedResponses::class]);
        }

        $errors = config('scramble-extensions.errors.enabled') === true;
        $security = config('scramble-extensions.security') === true;

        if ($errors) {
            Scramble::configure()->withRuleTransformers([RenderedErrors::class]);
        }

        $this->app->booted(static function () use ($errors, $security): void {
            Scramble::configure()
                ->withOperationTransformers(array_values(array_filter([
                    config('scramble-extensions.csrf') === true ? CsrfHeader::class : null,
                    $security ? Security::class : null,
                    $errors ? RenderedErrors::class : null,
                ])))
                ->withDocumentTransformers(array_values(array_filter([
                    $security ? Security::class : null,
                    $errors ? ErrorSet::class : null,
                ])));
        });

        // The code does not change between two deploys, so outside the local environment the document is
        // generated once, by `php artisan optimize` in the deploy, and served from Scramble's cache.
        if (! $this->app->isLocal()) {
            $this->optimizes('scramble:cache', 'scramble:clear');
        }
    }
}
