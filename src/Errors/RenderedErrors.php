<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Errors;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\Generator\UniqueNameOptions;
use Dedoc\Scramble\Support\RouteInfo;
use Dedoc\Scramble\Support\Type\ObjectType;
use Igne\ApiDocs\Validation\RequestRules;
use Igne\ApiDocs\Validation\ValidationSamples;
use Igne\ApiDocs\Validation\ValidationSchema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ReflectionNamedType;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Writes the error responses of an operation as the application really answers them: each exception
 * the operation can throw is built, rendered by the application's exception handler, and the answer
 * becomes the schema. Nothing describes an error format to this package.
 *
 * It is the only writer of error responses and runs after every other transformer.
 *
 * @phpstan-import-type Rules from RequestRules
 */
final readonly class RenderedErrors implements OperationTransformer
{
    public function __construct(
        private TypeTransformer $openApiTransformer,
        private AppAnswers $answers,
        private ErrorSchemas $schemas,
        private SchemaFromSample $schemaFromSample,
    ) {}

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $rules = RequestRules::take();

        $this->schemas->anyPath ??= $routeInfo->route->uri();

        // status => the content type, the headers and the names of the error schemas answered with it
        $answered = [];
        foreach ($routeInfo->getActionType()?->exceptions ?? [] as $type) {
            if (! $type instanceof ObjectType) {
                continue;
            }

            // Without a named input the request is unknown: its validation error is the one of any request.
            $isValidation = is_a($type->name, ValidationException::class, true);
            $requestRules = $isValidation && $this->hasNamedInput($rules) ? $rules : null;
            $samples = match (true) {
                $requestRules !== null => ValidationSamples::ofRequest($requestRules),
                $isValidation => ValidationSamples::ofAnyRequest(),
                default => ErrorSamples::of($type, $this->openApiTransformer),
            };

            $answers = array_values(array_filter(
                $this->answers->toEach($samples, $routeInfo->method, $routeInfo->route->uri()),
                static fn (array $answer): bool => $answer['status'] >= HttpResponse::HTTP_BAD_REQUEST,
            ));

            if ($answers === []) {
                continue;
            }

            // Several samples of one error (a validation error has two) merge into one schema.
            $status = $answers[0]['status'];
            $schemas = [];
            foreach ($answers as $answer) {
                $schema = $this->schemaFromSample->of($answer['body']);
                $schemas[] = $isValidation ? ValidationSchema::perInput($schema, $requestRules) : $schema;

                $answered[$status]['contentType'] = $answer['contentType'];
                $answered[$status]['headers'] = [...($answered[$status]['headers'] ?? []), ...$answer['headers']];
            }

            $schema = SchemaFromSample::merge($schemas);
            $name = $requestRules === null
                ? $this->schemas->add($schema, $type->name, $status)
                : $this->schemas->addValidationOf($this->requestName($operation, $routeInfo), $schema);

            $answered[$status]['names'][$name] = $name;
        }

        if ($answered === []) {
            return;
        }

        ksort($answered);

        // Scramble's own response for an answered status is replaced; any other response stays.
        $operation->responses = array_values(array_filter(
            $operation->responses ?? [],
            static fn (Response|Reference $response): bool => ! $response instanceof Response
                || ! isset($answered[(int) $response->code]),
        ));

        foreach ($answered as $status => $error) {
            $names = array_values($error['names']);
            $operation->addResponse($this->errorResponse($status, $error['contentType'], $error['headers'], $names));
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @param  list<string>  $names
     */
    private function errorResponse(int $status, string $contentType, array $headers, array $names): Response
    {
        $response = Response::make($status)
            ->setDescription(ErrorSchemas::reasonPhrase($status) ?? 'Error')
            ->setContent($contentType, RawSchema::asSchema($this->schemas->response($names)));

        foreach ($headers as $header => $value) {
            $response->addHeader($header, new Header(
                description: $header === 'Retry-After' ? 'The number of seconds to wait before sending the request again.' : null,
                schema: Schema::fromType(is_numeric($value) ? new IntegerType : new StringType),
            ));
        }

        return $response;
    }

    /**
     * Whether the rules name an input of the request, not only items of a list (`tags.*`).
     *
     * @param  Rules  $rules
     */
    private function hasNamedInput(array $rules): bool
    {
        return array_any(array_keys($rules), static fn (int|string $input): bool => ! str_contains((string) $input, '*'));
    }

    /**
     * The Form Request without its suffix (`LoginRequest` is `Login`), else the operation (`NewsIndex`).
     */
    private function requestName(Operation $operation, RouteInfo $routeInfo): string
    {
        foreach ($routeInfo->reflectionAction()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && is_a($type->getName(), FormRequest::class, true)) {
                return Str::beforeLast(class_basename($type->getName()), 'Request');
            }
        }

        // The operationId itself is only set once every operation is documented; its parts are already known.
        $operationName = $operation->getAttribute('operationId');
        $name = $operationName instanceof UniqueNameOptions
            ? ($operationName->eloquent ?: implode(' ', $operationName->unique))
            : $routeInfo->method.' '.$routeInfo->route->uri;

        return Str::studly((string) preg_replace('/[^A-Za-z0-9]+/', ' ', (string) $name));
    }
}
