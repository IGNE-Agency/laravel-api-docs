<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Validation;

use Igne\ApiDocs\Errors\SchemaFromSample;

/**
 * Turns the schema of a worst-case validation answer into the schema of the validation error of a
 * request: in the worst case every input failed, in a real answer only some did.
 */
final class ValidationSchema
{
    /**
     * Rewrites the part of a validation answer that lists the inputs, which is the object that has
     * the inputs of the request as its members:
     *
     * - an input an answer names is no longer required: it is only there when it failed;
     * - an input the request cannot name (an item of a list), or any input of a request nobody
     *   knows, becomes what every other member looks like.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, list<array{0: string, 1: list<mixed>}>>|null  $rules  null for the validation error of any request
     * @return array<string, mixed>
     */
    public static function perInput(array $schema, ?array $rules): array
    {
        $inputs = $rules === null ? [ValidationSamples::ANY_INPUT] : array_map(strval(...), array_keys($rules));
        $attributes = array_map(ValidationSamples::attribute(...), $inputs);

        if (isset($schema['properties']) && array_diff($attributes, array_keys($schema['properties'])) === []) {
            $unnamed = [];
            foreach ($inputs as $input) {
                if ($rules === null || str_contains($input, '*')) {
                    $unnamed[] = $schema['properties'][ValidationSamples::attribute($input)];
                    unset($schema['properties'][ValidationSamples::attribute($input)]);
                }
            }

            unset($schema['required']);

            return array_filter([
                ...$schema,
                'additionalProperties' => $unnamed === [] ? null : SchemaFromSample::merge($unnamed),
            ], static fn (mixed $member): bool => $member !== null && $member !== []);
        }

        foreach ($schema['properties'] ?? [] as $member => $property) {
            if (is_array($property)) {
                $schema['properties'][$member] = self::perInput($property, $rules);
            }
        }

        return $schema;
    }
}
