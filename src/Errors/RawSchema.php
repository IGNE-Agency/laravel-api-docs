<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Errors;

use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\Type;

/**
 * A schema that is already written as a plain OpenAPI array, placed in Scramble's document as it is.
 */
final class RawSchema extends Type
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(private readonly array $schema)
    {
        parent::__construct('object');
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    public static function asSchema(array $schema): Schema
    {
        return Schema::fromType(new self($schema));
    }

    public function serializeAs31(): mixed
    {
        return $this->schema;
    }

    public function serializeAs32(): mixed
    {
        return $this->schema;
    }

    public function toArray()
    {
        return $this->schema;
    }
}
