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
 * Documents a paginated list with named schemas instead of one inline schema per endpoint:
 * `PaginationLinks`, `PaginationMeta` and `Paginated<Resource>`.
 *
 * The first one met becomes the shared schema; a response that differs from it keeps its inline
 * description.
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
     * The `Paginated<Resource>` reference, or the response itself when its items are no resource.
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
