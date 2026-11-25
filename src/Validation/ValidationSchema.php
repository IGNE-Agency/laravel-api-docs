<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Validation;

use Igne\ApiDocs\Errors\SchemaFromSample;

/**
 * Turns the schema of a worst-case validation answer into the schema of a real one: in the worst
 * case every input failed, in a real answer only some did.
 *
 * @phpstan-import-type Rules from RequestRules
 */
final class ValidationSchema
{
    /**
     * Rewrites the object that has the inputs of the request as its members, wherever it sits in
     * the answer: no input is required, and an input the request cannot name (an item of a list,
     * any input of an unknown request) becomes `additionalProperties`.
     *
     * @param  array<string, mixed>  $schema
     * @param  Rules|null  $rules  null for the validation error of any request
     * @return array<string, mixed>
     */
    public static function perInput(array $schema, ?array $rules): array
    {
        $inputs = $rules === null ? [ValidationSamples::ANY_INPUT] : array_map(strval(...), array_keys($rules));
        $attributes = array_map(ValidationSamples::attribute(...), $inputs);

        if (isset($schema['properties']) && array_diff($attributes, array_keys($schema['properties'])) === []) {
            $unnamed = [];
            foreach ($inputs as $index => $input) {
                if ($rules === null || str_contains($input, '*')) {
                    $unnamed[] = $schema['properties'][$attributes[$index]];
                    unset($schema['properties'][$attributes[$index]]);
                }
            }

            unset($schema['required']);

            return array_filter(
                [...$schema, 'additionalProperties' => SchemaFromSample::merge($unnamed)],
                static fn (mixed $member): bool => $member !== [],
            );
        }

        foreach ($schema['properties'] ?? [] as $member => $property) {
            $schema['properties'][$member] = self::perInput($property, $rules);
        }

        return $schema;
    }
}
