<?php

declare(strict_types=1);

namespace Igne\ApiDocs;

use Dedoc\Scramble\Configuration\OperationTransformers;
use Dedoc\Scramble\Scramble;
use Igne\ApiDocs\Errors\AppAnswers;
use Igne\ApiDocs\Errors\ErrorSchemas;
use Igne\ApiDocs\Errors\ErrorSet;
use Igne\ApiDocs\Errors\RenderedErrors;
use Igne\ApiDocs\Errors\SchemaFromSample;
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
final class ApiDocsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/api-docs.php', 'api-docs');

        $this->app->singleton(AppAnswers::class);
        $this->app->singleton(ErrorSchemas::class, static fn (): ErrorSchemas => new ErrorSchemas((string) config('api-docs.errors.suffix', 'Error')));
        $this->app->bind(SchemaFromSample::class, static fn (): SchemaFromSample => new SchemaFromSample(array_values(array_filter((array) config('api-docs.errors.free_text', []), is_string(...)))));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/api-docs.php' => $this->app->configPath('api-docs.php')], 'api-docs-config');

        if (config('api-docs.called_methods') === true) {
            Scramble::registerExtensions([ExceptionsOfCalledMethods::class]);
        }

        if (config('api-docs.bound_responses') === true) {
            Scramble::registerExtensions([BoundResponses::class, ContainerCalls::class]);
        }

        if (config('api-docs.packages') === true) {
            Scramble::infer()->configure()->buildDefinitionsUsingAstFor([new RoutedPackages($this->app->make('router'))]);
        }

        if (config('api-docs.middleware') === true) {
            Scramble::configure()->withOperationTransformers(
                static fn (OperationTransformers $transformers): OperationTransformers => $transformers->prepend(ExceptionsOfMiddleware::class),
            );
        }

        if (config('api-docs.pagination') === true) {
            Scramble::configure()->withOperationTransformers([PaginatedResponses::class]);
        }

        $errors = config('api-docs.errors.enabled') === true;
        $security = config('api-docs.security') === true;

        if ($errors) {
            Scramble::configure()->withRuleTransformers([RenderedErrors::class]);
        }

        $this->app->booted(static function () use ($errors, $security): void {
            Scramble::configure()
                ->withOperationTransformers(array_values(array_filter([
                    config('api-docs.csrf') === true ? CsrfHeader::class : null,
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
