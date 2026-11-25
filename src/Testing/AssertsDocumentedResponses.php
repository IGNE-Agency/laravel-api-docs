<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Testing;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * For the tests of an application or a package: asserts that a real response is what the generated
 * document says the operation answers. The status and the content type must be documented, and a
 * JSON body must fit the documented schema.
 *
 * A failure means the code and the document disagree: fix the code, never the assertion.
 */
trait AssertsDocumentedResponses
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $openApiDocument = null;

    /**
     * @param  string  $path  the path as the document names it, with its parameters (`/articles/{article}`)
     */
    protected function assertDocumented(TestResponse $response, string $method, string $path): TestResponse
    {
        $this->openApiDocument ??= GeneratedDocument::of(static::class);

        $operation = "{$method} {$path}";
        $responses = $this->openApiDocument['paths'][$path][strtolower($method)]['responses'] ?? null;
        $status = (string) $response->getStatusCode();

        Assert::assertNotNull($responses, "[{$operation}] is not in the document.");
        Assert::assertArrayHasKey($status, $responses, "[{$operation}] answered {$status}, which the document does not list.");

        $content = $responses[$status]['content'] ?? [];
        $contentType = trim(explode(';', (string) $response->headers->get('Content-Type'))[0]);

        if ($content === []) {
            // An empty JSON value is no body: Laravel answers `""` for `new JsonResponse('')`.
            Assert::assertContains((string) $response->getContent(), ['', '""', 'null', '[]', '{}'], "[{$operation}] answered {$status} with a body, and the document lists none.");

            return $response;
        }

        Assert::assertArrayHasKey($contentType, $content, "[{$operation}] answered {$status} as {$contentType}, which the document does not list.");

        $mismatch = $this->openApiMismatch($response->json(), $content[$contentType]['schema'] ?? [], '$');

        Assert::assertNull($mismatch, "[{$operation}] answered {$status} with a body the document does not describe: {$mismatch}");

        return $response;
    }

    /**
     * Where a value does not fit a schema, or null when it does. Reads what the document of this
     * package is written in: types, fixed values, required members, lists, alternatives and references.
     *
     * @param  array<string, mixed>  $schema
     */
    private function openApiMismatch(mixed $value, array $schema, string $at): ?string
    {
        if (isset($schema['$ref'])) {
            return $this->openApiMismatch($value, $this->openApiDocument['components']['schemas'][Str::afterLast($schema['$ref'], '/')] ?? [], $at);
        }

        foreach ($schema['allOf'] ?? [] as $part) {
            if (($mismatch = $this->openApiMismatch($value, $part, $at)) !== null) {
                return $mismatch;
            }
        }

        if (($alternatives = $schema['oneOf'] ?? $schema['anyOf'] ?? null) !== null) {
            $mismatches = array_map(fn (array $alternative): ?string => $this->openApiMismatch($value, $alternative, $at), $alternatives);

            return in_array(null, $mismatches, true) ? null : "{$at} fits none of the alternatives (".implode('; ', array_unique($mismatches)).')';
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            return "{$at} is ".json_encode($value).', not '.json_encode($schema['const']);
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return "{$at} is ".json_encode($value).', which is not one of '.json_encode($schema['enum']);
        }

        $types = (array) ($schema['type'] ?? []);

        if ($types !== [] && ! in_array($this->openApiTypeOf($value, $types), $types, true)) {
            return "{$at} is no ".implode('|', $types);
        }

        if (is_array($value) && in_array('object', $types, true)) {
            foreach ($schema['required'] ?? [] as $member) {
                if (! array_key_exists($member, $value)) {
                    return "{$at}.{$member} is missing";
                }
            }

            foreach ($value as $member => $memberValue) {
                $memberSchema = $schema['properties'][$member] ?? (is_array($schema['additionalProperties'] ?? null) ? $schema['additionalProperties'] : null);

                if ($memberSchema !== null && ($mismatch = $this->openApiMismatch($memberValue, $memberSchema, "{$at}.{$member}")) !== null) {
                    return $mismatch;
                }
            }
        }

        if (is_array($value) && in_array('array', $types, true) && isset($schema['items'])) {
            foreach ($value as $index => $item) {
                if (($mismatch = $this->openApiMismatch($item, $schema['items'], "{$at}[{$index}]")) !== null) {
                    return $mismatch;
                }
            }
        }

        return null;
    }

    /**
     * The JSON type of a decoded value. An empty PHP array is the object or the list the schema asks for.
     *
     * @param  list<string>  $expected
     */
    private function openApiTypeOf(mixed $value, array $expected): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => in_array('integer', $expected, true) ? 'integer' : 'number',
            is_float($value) => 'number',
            is_string($value) => 'string',
            $value === null => 'null',
            $value === [] => in_array('object', $expected, true) ? 'object' : 'array',
            is_array($value) && array_is_list($value) => 'array',
            default => 'object',
        };
    }
}
