<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Errors;

use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * The named schemas of the errors of one document.
 *
 * An answer with one fixed value of its own (a `code`) is named after it (`two_factor_required` is
 * `TwoFactorRequiredError`). An answer without one (Laravel's `{ message }`) is named after its
 * status.
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
     * The validation errors of single requests, which are no part of the closed set.
     *
     * @var array<string, true>
     */
    private array $ofOneRequest = [];

    /**
     * A path of the document, to ask the application about the errors any request can get.
     */
    public ?string $anyPath = null;

    public function __construct(private readonly string $suffix) {}

    /**
     * @param  array<string, mixed>  $schema
     * @param  class-string|null  $exceptionClass
     */
    public function add(array $schema, ?string $exceptionClass, int $status): string
    {
        $fixed = self::fixedValues($schema);
        $code = count($fixed) === 1 ? reset($fixed) : null;
        $phrase = self::reasonPhrase($status) ?? 'Error '.$status;

        $name = Str::studly($code ?? Str::slug($phrase, '_'));
        // `internal_server_error` is InternalServerError, not InternalServerErrorError.
        $name = str_ends_with($name, $this->suffix) ? $name : $name.$this->suffix;
        $this->put($name, $schema);

        // Only an answer with a code of its own is the error of one class, and takes its description.
        $summary = $code === null ? null : self::summaryOf($exceptionClass);

        if ($summary !== null) {
            $this->descriptions[$name] = $summary;
        } else {
            $this->descriptions[$name] ??= self::DESCRIPTIONS[$status] ?? $phrase.'.';
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    public function addValidationOf(string $requestName, array $schema): string
    {
        $name = $requestName.'Validation'.$this->suffix;
        $this->put($name, $schema);

        $this->ofOneRequest[$name] = true;
        $this->descriptions[$name] = "A validation error of {$requestName}: its inputs and the rules each can fail.";

        return $name;
    }

    /**
     * The error itself, or a `oneOf` told apart by the member that is fixed and different in each.
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
     * Every error the API can answer, and the values that tell them apart.
     *
     * @return array{errors: array<string, mixed>, codes: array<string, mixed>|null}|null
     */
    public function closedSet(): ?array
    {
        $names = array_keys(array_diff_key($this->schemas, $this->ofOneRequest));
        sort($names);

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

    public static function reasonPhrase(int $status): ?string
    {
        return $status === 419 ? 'Page Expired' : (Response::$statusTexts[$status] ?? null);
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function put(string $name, array $schema): void
    {
        $this->schemas[$name] = isset($this->schemas[$name]) ? SchemaFromSample::merge([$this->schemas[$name], $schema]) : $schema;
    }

    /**
     * The member that is a fixed value in every schema and a different one in each.
     *
     * @param  list<string>  $names
     * @return array{propertyName: string, mapping: array<string, string>}|null
     */
    private function discriminator(array $names): ?array
    {
        foreach (array_keys(self::fixedValues($this->schemas[$names[0]])) as $member) {
            $mapping = [];

            foreach ($names as $name) {
                $value = self::fixedValues($this->schemas[$name])[$member] ?? null;

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
     * The top-level members that are one fixed string.
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

    /**
     * The first paragraph of the docblock of an exception class, unless the class is the framework's.
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
        foreach (explode("\n", Str::beforeLast($docComment, '*/')) as $line) {
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
