<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Errors;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Infer;
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
 * Documents the error responses of every operation as the application really answers them.
 *
 * Nothing describes the shape of an error to this package. For each exception Scramble knows an
 * operation can throw (its action, the methods it calls, its middleware), a real exception is
 * built ({@see ErrorSamples}), the application's own exception handler answers it
 * ({@see AppAnswers}), and the answer is written down as a schema ({@see SchemaFromSample}). An
 * application that answers Laravel's `{ message }` is documented that way, one that answers RFC
 * 9457 Problem Details that way, with no setting to change.
 *
 * It is the only writer of error responses and runs after every other transformer. The validation
 * error of a request is built from the rules {@see RequestRules} recorded for the operation.
 */
final class RenderedErrors implements OperationTransformer
{
    private readonly ErrorSamples $samples;

    public function __construct(
        Infer $infer,
        TypeTransformer $openApiTransformer,
        private readonly AppAnswers $answers,
        private readonly ErrorSchemas $schemas,
        private readonly SchemaFromSample $schemaFromSample,
        private readonly ValidationSamples $validationSamples,
    ) {
        $this->samples = new ErrorSamples($infer, $openApiTransformer);
    }

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $rules = RequestRules::take();

        $this->schemas->anyPath ??= $routeInfo->route->uri();

        $answered = [];
        foreach ($routeInfo->getActionType()?->exceptions ?? [] as $type) {
            if (! $type instanceof ObjectType) {
                continue;
            }

            $isValidation = is_a($type->name, ValidationException::class, true);
            $ofRequest = $isValidation && $this->namedInputs($rules) !== [];

            // Several samples of one error (a validation error has two) are answers to merge into one schema.
            $status = null;
            $schemas = [];
            $samples = match (true) {
                $ofRequest => $this->validationSamples->ofRequest($rules),
                $isValidation => $this->validationSamples->ofAnyRequest(),
                default => $this->samples->of($type),
            };

            foreach ($samples as $exception) {
                $answer = $this->answers->to($exception, $routeInfo->method, $routeInfo->route->uri());

                if ($answer === null || $answer['status'] < HttpResponse::HTTP_BAD_REQUEST) {
                    continue;
                }

                $status ??= $answer['status'];
                $schema = $this->schemaFromSample->of($answer['body']);
                $schemas[] = $isValidation ? ValidationSchema::perInput($schema, $ofRequest ? $rules : null) : $schema;

                $answered[$status]['contentType'] = $answer['contentType'];
                $answered[$status]['headers'] = [...($answered[$status]['headers'] ?? []), ...$answer['headers']];
            }

            if ($status === null) {
                continue;
            }

            $name = $this->schemas->add(SchemaFromSample::merge($schemas), $type->name, $status, $ofRequest ? $this->requestName($operation, $routeInfo) : null);
            $answered[$status]['names'][$name] = $name;
        }

        if ($answered === []) {
            return;
        }

        ksort($answered);

        // What Scramble documented for a status the application was asked about is replaced; any other response stays.
        $operation->responses = array_values(array_filter(
            $operation->responses ?? [],
            static fn (Response|Reference $response): bool => ! $response instanceof Response || ! isset($answered[(int) $response->code]),
        ));

        foreach ($answered as $status => $error) {
            $response = Response::make($status)
                ->setDescription($status === 419 ? 'Page Expired' : (HttpResponse::$statusTexts[$status] ?? 'Error'))
                ->setContent($error['contentType'], Schema::fromType(new RawSchema($this->schemas->response(array_values($error['names'])))));

            foreach ($error['headers'] as $header => $value) {
                $response->addHeader($header, new Header(
                    description: $header === 'Retry-After' ? 'The number of seconds to wait before sending the request again.' : null,
                    schema: Schema::fromType(is_numeric($value) ? new IntegerType : new StringType),
                ));
            }

            $operation->addResponse($response);
        }
    }

    /**
     * @param  array<string, list<array{0: string, 1: list<mixed>}>>  $rules
     * @return list<string>
     */
    private function namedInputs(array $rules): array
    {
        return array_values(array_filter(array_map(strval(...), array_keys($rules)), static fn (string $input): bool => ! str_contains($input, '*')));
    }

    /**
     * The name of the request a validation error belongs to: its Form Request without the
     * `Request` suffix (`LoginRequest` is `Login`), or else the operation (route `api.news.index` is `NewsIndex`).
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
