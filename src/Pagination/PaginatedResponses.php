<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Pagination;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * Documents a paginated list as named schemas, so a client generates reusable types instead of one
 * inline type per endpoint: the `links` and `meta` members once, as `PaginationLinks` and
 * `PaginationMeta`, and the response itself as `Paginated<Resource>`, named after the resource of
 * its `data` items (`PaginatedArticleResource`).
 *
 * Scramble describes all of it inline on every paginated response; the first one met becomes the
 * shared schema. A response or member that differs from the schema of its name (another paginator, a
 * resource with its own `paginationInformation()`) keeps its inline description, and so does a
 * response whose `data` items are not a resource. The JSON of the endpoints is untouched.
 */
final readonly class PaginatedResponses implements OperationTransformer
{
    /** @var array<string, string> the shared schema name per member of the paginated response */
    private const array SCHEMAS = [
        'links' => 'PaginationLinks',
        'meta' => 'PaginationMeta',
    ];

    public function __construct(private TypeTransformer $openApiTransformer) {}

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        foreach ($operation->responses ?? [] as $response) {
            $body = $response instanceof Response ? ($response->content['application/json'] ?? null) : null;
            $body = $body instanceof Schema ? $body->type : null;

            if (! $body instanceof ObjectType || array_diff(['data', ...array_keys(self::SCHEMAS)], array_keys($body->properties)) !== []) {
                continue;
            }

            foreach (self::SCHEMAS as $member => $name) {
                $body->addProperty($member, $this->shared($name, $body->getProperty($member)));
            }

            $response->setContent('application/json', Schema::fromType($this->named($body)));
        }
    }

    /**
     * The reference to the `Paginated<Resource>` schema of the response, the response itself when its
     * `data` items are not a resource.
     */
    private function named(ObjectType $body): Type
    {
        $data = $body->getProperty('data');
        $resource = $data instanceof ArrayType ? $data->items : null;

        if (! $resource instanceof Reference || $resource->referenceType !== 'schemas') {
            return $body;
        }

        return $this->shared('Paginated'.$resource->getUniqueName(), $body);
    }

    /**
     * The reference to the shared schema when the type has its shape, the type itself otherwise.
     */
    private function shared(string $name, ?Type $type): ?Type
    {
        if (! $type instanceof ObjectType) {
            return $type;
        }

        $components = $this->openApiTransformer->getComponents();
        $reference = $this->openApiTransformer->getOrCreateSchemaReference(new Reference('schemas', $name, $components), fn (): Type => $type);

        return $components->getSchema($name)->type->toArray() === $type->toArray() ? $reference : $type;
    }
}
