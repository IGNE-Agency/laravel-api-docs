<?php

declare(strict_types=1);

namespace Igne\ApiDocs;

use Dedoc\Scramble\Configuration\OperationTransformers;
use Dedoc\Scramble\Scramble;
use Igne\ApiDocs\CalledMethods\ExceptionsOfCalledMethods;
use Igne\ApiDocs\Csrf\CsrfHeader;
use Igne\ApiDocs\Errors\AppAnswers;
use Igne\ApiDocs\Errors\ErrorSchemas;
use Igne\ApiDocs\Errors\ErrorSet;
use Igne\ApiDocs\Errors\RenderedErrors;
use Igne\ApiDocs\Errors\SchemaFromSample;
use Igne\ApiDocs\Fortify\BoundResponses;
use Igne\ApiDocs\Fortify\ContainerCalls;
use Igne\ApiDocs\Middleware\ExceptionsOfMiddleware;
use Igne\ApiDocs\Packages\RoutedPackages;
use Igne\ApiDocs\Pagination\PaginatedResponses;
use Igne\ApiDocs\Security\SecuritySchemes;
use Igne\ApiDocs\Validation\RequestRules;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the rules the config turns on. Each applies to every route of the document, whoever
 * registered it.
 *
 * A rule that adds knowledge (what an operation can throw) runs before Scramble's own transformers.
 * A rule that writes the document runs once every provider has booted, after everyone else's. So
 * the result does not depend on the order packages boot in.
 */
final class ApiDocsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/api-docs.php', 'api-docs');

        $this->app->singleton(AppAnswers::class);
        $this->app->singleton(
            ErrorSchemas::class,
            static fn (): ErrorSchemas => new ErrorSchemas((string) config('api-docs.errors.suffix', 'Error')),
        );
        $this->app->bind(
            SchemaFromSample::class,
            static fn (): SchemaFromSample => new SchemaFromSample(
                array_values(array_filter((array) config('api-docs.errors.free_text', []), is_string(...))),
            ),
        );
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

        $this->app->booted(static function () use ($errors, $security): void {
            Scramble::configure()
                ->withRuleTransformers($errors ? [RequestRules::class] : [])
                ->withOperationTransformers(array_values(array_filter([
                    config('api-docs.csrf') === true ? CsrfHeader::class : null,
                    $security ? SecuritySchemes::class : null,
                    $errors ? RenderedErrors::class : null,
                ])))
                ->withDocumentTransformers(array_values(array_filter([
                    $security ? SecuritySchemes::class : null,
                    $errors ? ErrorSet::class : null,
                ])));
        });

        // Outside local the document is generated once per deploy (`php artisan optimize`) and served from
        // Scramble's cache.
        if (! $this->app->isLocal()) {
            $this->optimizes('scramble:cache', 'scramble:clear');
        }
    }
}
