<?php

declare(strict_types=1);

namespace Igne\ScrambleExtensions\Errors;

use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * The named schemas of the errors of one document, so a client generates a type for each.
 *
 * An answer that carries one fixed value of its own (a `code`) is named after that value
 * (`two_factor_required` is `TwoFactorRequiredError`), so every exception the application answers
 * the same way is the same schema. An answer without one (Laravel's `{ message }`) is named after
 * its status: nothing in it tells two errors of that status apart.
 */
final class ErrorSchemas
{
    /**
     * When an error of the framework happens and what a client does about it.
     *
     * @var array<int, string>
     */
    private const array DESCRIPTIONS = [
        400 => 'The request is malformed. Fix the request before sending it again.',
        401 => 'The request is not authenticated. Log in and send it again.',
        403 => 'The user may not do this. Do not retry; hide or disable the action.',
        404 => 'The resource or the path does not exist, or the user may not see it.',
        405 => 'The path does not accept this HTTP method.',
        413 => 'The request body is larger than the server accepts.',
        419 => 'The CSRF token of the session expired. Fetch a new CSRF cookie and send the request again.',
        422 => 'One or more inputs are invalid. Show the errors per input and let the user correct them.',
        429 => 'Too many requests. Wait the number of seconds of the `Retry-After` header before sending it again.',
        500 => 'An unexpected error on the server. Retry later.',
        503 => 'The API is unavailable, for maintenance for example. Retry later.',
    ];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $schemas = [];

    /**
     * @var array<string, string>
     */
    private array $descriptions = [];

    /**
     * The schemas of one request each (its validation error), which are no error of their own.
     *
     * @var array<string, true>
     */
    private array $ofOneRequest = [];

    /**
     * The path of an operation of the document, to ask the application about the errors any request can get.
     */
    public ?string $anyPath = null;

    public function __construct(private readonly string $suffix) {}

    /**
     * @param  array<string, mixed>  $schema
     * @param  class-string|null  $exceptionClass
     * @param  string|null  $requestName  the request the schema belongs to, when it is the validation error of one request
     */
    public function add(array $schema, ?string $exceptionClass, int $status, ?string $requestName = null): string
    {
        $name = $requestName !== null ? $requestName.'Validation'.$this->suffix : $this->nameOf($schema, $status);

        $this->schemas[$name] = isset($this->schemas[$name]) ? SchemaFromSample::merge([$this->schemas[$name], $schema]) : $schema;

        if ($requestName !== null) {
            $this->ofOneRequest[$name] = true;
            $this->descriptions[$name] = "A validation error of {$requestName}: its inputs and the rules each can fail.";
        } elseif (count(self::fixedValues($schema)) === 1 && ($summary = self::summaryOf($exceptionClass)) !== null) {
            // Only an answer that is told apart from the others is the error of one class; one named after its
            // status stands for every exception of that status.
            $this->descriptions[$name] = $summary;
        } else {
            $this->descriptions[$name] ??= self::DESCRIPTIONS[$status] ?? self::reasonPhrase($status).'.';
        }

        return $name;
    }

    /**
     * The body of an error response: the error itself, or one of several told apart by the member
     * that is fixed and different in each.
     *
     * @param  list<string>  $names
     * @return array<string, mixed>
     */
    public function response(array $names): array
    {
        if (count($names) === 1) {
            return self::reference($names[0]);
        }

        return array_filter([
            'oneOf' => array_map(self::reference(...), $names),
            'discriminator' => $this->discriminator($names),
        ]);
    }

    /**
     * Every schema, with its description, to write into the document.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $all = [];
        foreach ($this->schemas as $name => $schema) {
            $all[$name] = ['description' => $this->descriptions[$name], ...$schema];
        }

        ksort($all);

        return $all;
    }

    /**
     * The closed set: every error the API can answer, and the values of the member that tells them apart.
     *
     * @return array{errors: array<string, mixed>, codes: array<string, mixed>|null}|null
     */
    public function closedSet(): ?array
    {
        $names = array_values(array_diff(array_keys($this->all()), array_keys($this->ofOneRequest)));

        if ($names === []) {
            return null;
        }

        $discriminator = $this->discriminator($names);

        return [
            'errors' => ['description' => 'Every error the API can answer.', ...$this->response($names)],
            'codes' => $discriminator === null ? null : [
                'description' => "The `{$discriminator['propertyName']}` of every error the API can answer, which a client switches on.",
                'type' => 'string',
                'enum' => array_keys($discriminator['mapping']),
            ],
        ];
    }

    public function reset(): void
    {
        $this->schemas = $this->descriptions = $this->ofOneRequest = [];
        $this->anyPath = null;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function nameOf(array $schema, int $status): string
    {
        $fixed = self::fixedValues($schema);

        $name = Str::studly(count($fixed) === 1 ? (string) reset($fixed) : Str::slug(self::reasonPhrase($status), '_'));

        // `internal_server_error` is InternalServerError, not InternalServerErrorError.
        return str_ends_with($name, $this->suffix) ? $name : $name.$this->suffix;
    }

    /**
     * The member that is a fixed value in each of the schemas and a different one in each.
     *
     * @param  list<string>  $names
     * @return array{propertyName: string, mapping: array<string, string>}|null
     */
    private function discriminator(array $names): ?array
    {
        foreach (array_keys(self::fixedValues($this->schemas[$names[0]] ?? [])) as $member) {
            $mapping = [];

            foreach ($names as $name) {
                $value = self::fixedValues($this->schemas[$name] ?? [])[$member] ?? null;

                if ($value === null || isset($mapping[$value])) {
                    continue 2;
                }

                $mapping[$value] = '#/components/schemas/'.$name;
            }

            ksort($mapping);

            return ['propertyName' => (string) $member, 'mapping' => $mapping];
        }

        return null;
    }

    /**
     * The top-level members of a schema that are one fixed string.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, string>
     */
    private static function fixedValues(array $schema): array
    {
        $fixed = [];
        foreach ($schema['properties'] ?? [] as $member => $property) {
            if (is_string($property['const'] ?? null)) {
                $fixed[(string) $member] = $property['const'];
            }
        }

        return $fixed;
    }

    /**
     * @return array<string, string>
     */
    private static function reference(string $name): array
    {
        return ['$ref' => '#/components/schemas/'.$name];
    }

    private static function reasonPhrase(int $status): string
    {
        return $status === 419 ? 'Page Expired' : (Response::$statusTexts[$status] ?? 'Error '.$status);
    }

    /**
     * The first paragraph of the docblock of an exception class that is not the framework's own:
     * such a class is written for one error, and says when it happens.
     *
     * @param  class-string|null  $class
     */
    private static function summaryOf(?string $class): ?string
    {
        if ($class === null || ! class_exists($class) || Str::startsWith($class, ['Illuminate\\', 'Symfony\\', 'Laravel\\'])) {
            return null;
        }

        $docComment = (new ReflectionClass($class))->getDocComment();

        if ($docComment === false) {
            return null;
        }

        $summary = [];
        foreach (explode("\n", $docComment) as $line) {
            $line = trim(ltrim(trim($line), '/*'));

            if (str_starts_with($line, '@') || ($line === '' && $summary !== [])) {
                break;
            }

            if ($line !== '') {
                $summary[] = $line;
            }
        }

        return $summary === [] ? null : implode(' ', $summary);
    }
}
