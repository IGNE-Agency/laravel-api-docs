<?php

declare(strict_types=1);

namespace Igne\ScrambleExtensions\Errors;

/**
 * Writes the schema of a real JSON answer. Nothing is known about the shape beforehand, so the
 * values themselves tell it:
 *
 * - a string that looks like an identifier (`two_factor_required`, `required`) is a fixed value,
 *   unless it is the value of a member with a free-text name (`message`, `detail`);
 * - a UUID or a URI gets its format, any other string is a string;
 * - no value is copied into the schema except a fixed one, so the document does not change with
 *   the machine, the moment or the language it is generated in.
 */
final readonly class SchemaFromSample
{
    /**
     * @param  list<string>  $freeText  the names of members that hold text for a person
     */
    public function __construct(private array $freeText) {}

    /**
     * @return array<string, mixed>
     */
    public function of(mixed $value, ?string $member = null): array
    {
        return match (true) {
            is_bool($value) => ['type' => 'boolean'],
            is_int($value) => ['type' => 'integer'],
            is_float($value) => ['type' => 'number'],
            is_string($value) => $this->string($value, $member),
            $value === [] => ['type' => 'array'],
            is_array($value) && array_is_list($value) => ['type' => 'array', 'items' => self::merge(array_map(fn (mixed $item): array => $this->of($item), $value))],
            is_array($value) => $this->object($value),
            default => ['type' => 'null'],
        };
    }

    /**
     * One schema that holds for every one of the given: the same member in several becomes the
     * merge of them, fixed values become an enum, a member some lack is no longer required.
     *
     * @param  list<array<string, mixed>>  $schemas
     * @return array<string, mixed>
     */
    public static function merge(array $schemas): array
    {
        // In the order they were met, each once.
        $unique = [];
        foreach ($schemas as $schema) {
            $unique[(string) json_encode($schema)] ??= $schema;
        }
        $schemas = array_values($unique);

        if (count($schemas) <= 1) {
            return $schemas[0] ?? [];
        }

        $types = array_values(array_unique(array_map(static fn (array $schema): mixed => $schema['type'] ?? null, $schemas), SORT_REGULAR));

        if ($types === ['object']) {
            $properties = [];
            foreach ($schemas as $schema) {
                foreach ($schema['properties'] ?? [] as $name => $property) {
                    $properties[$name][] = $property;
                }
            }

            $required = array_values(array_intersect(...array_map(static fn (array $schema): array => $schema['required'] ?? [], $schemas)));
            $others = array_values(array_filter(array_map(static fn (array $schema): ?array => $schema['additionalProperties'] ?? null, $schemas)));

            return array_filter([
                'type' => 'object',
                'properties' => array_map(self::merge(...), $properties),
                'required' => $required,
                'additionalProperties' => $others === [] ? [] : self::merge($others),
            ], static fn (mixed $member): bool => $member !== []);
        }

        if ($types === ['string']) {
            $values = [];
            foreach ($schemas as $schema) {
                if (! isset($schema['const']) && ! isset($schema['enum'])) {
                    $formats = array_unique(array_map(static fn (array $schema): ?string => $schema['format'] ?? null, $schemas));

                    return count($formats) === 1 && $formats[0] !== null ? ['type' => 'string', 'format' => $formats[0]] : ['type' => 'string'];
                }

                $values = [...$values, ...($schema['enum'] ?? [$schema['const']])];
            }

            return ['type' => 'string', 'enum' => array_values(array_unique($values))];
        }

        if ($types === ['array']) {
            $items = array_values(array_filter(array_map(static fn (array $schema): ?array => $schema['items'] ?? null, $schemas)));

            return $items === [] ? ['type' => 'array'] : ['type' => 'array', 'items' => self::merge($items)];
        }

        return count($types) === 1 && is_string($types[0]) ? ['type' => $types[0]] : ['anyOf' => $schemas];
    }

    /**
     * @return array<string, mixed>
     */
    private function string(string $value, ?string $member): array
    {
        return match (true) {
            $member !== null && in_array($member, $this->freeText, true) => ['type' => 'string'],
            preg_match('/^(?=.*[a-z])[a-z0-9]+(?:_[a-z0-9]+)*$/', $value) === 1 => ['type' => 'string', 'const' => $value],
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1 => ['type' => 'string', 'format' => 'uuid'],
            preg_match('~^[a-z][a-z0-9+.-]*:\S+$~i', $value) === 1 => ['type' => 'string', 'format' => 'uri'],
            default => ['type' => 'string'],
        };
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    private function object(array $value): array
    {
        $properties = [];
        foreach ($value as $name => $member) {
            $properties[(string) $name] = $this->of($member, (string) $name);
        }

        return ['type' => 'object', 'properties' => $properties, 'required' => array_map(strval(...), array_keys($properties))];
    }
}
