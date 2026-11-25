<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Errors;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Igne\ApiDocs\Validation\ValidationSamples;
use Igne\ApiDocs\Validation\ValidationSchema;

/**
 * Closes the document once every operation is documented: adds the errors any request can get,
 * writes the schema of every error, and the closed set of them all (`ApiError`, `ApiErrorCode`), so
 * a client can handle every error in one place.
 */
final readonly class ErrorSet implements DocumentTransformer
{
    public function __construct(
        private AppAnswers $answers,
        private ErrorSchemas $schemas,
        private SchemaFromSample $schemaFromSample,
    ) {}

    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $path = $this->schemas->anyPath;

        if ($path === null) {
            return;
        }

        foreach (ErrorSamples::ofAnyRequest() as $exception) {
            $answer = $this->answers->to($exception, 'GET', $path);

            if ($answer !== null) {
                $this->schemas->add($this->schemaFromSample->of($answer['body']), $exception::class, $answer['status']);
            }
        }

        // The validation error of a request nobody knows.
        $answers = $this->answers->toEach(ValidationSamples::ofAnyRequest(), 'POST', $path);

        if ($answers !== []) {
            $schemas = array_map(
                fn (array $answer): array => ValidationSchema::perInput($this->schemaFromSample->of($answer['body']), null),
                $answers,
            );

            $this->schemas->add(SchemaFromSample::merge($schemas), null, $answers[0]['status']);
        }

        foreach ($this->schemas->all() as $name => $schema) {
            $document->components->addSchema($name, RawSchema::asSchema($schema));
        }

        $closedSet = $this->schemas->closedSet();

        if ($closedSet !== null) {
            $document->components->addSchema((string) config('api-docs.errors.set', 'ApiError'), RawSchema::asSchema($closedSet['errors']));

            if ($closedSet['codes'] !== null) {
                $document->components->addSchema((string) config('api-docs.errors.codes', 'ApiErrorCode'), RawSchema::asSchema($closedSet['codes']));
            }
        }

        $this->schemas->reset();
    }
}
