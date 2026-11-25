<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Errors;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Igne\ApiDocs\Validation\ValidationSamples;
use Igne\ApiDocs\Validation\ValidationSchema;

/**
 * Closes the document once every operation is documented: writes the schema of each error the
 * operations answer, adds the errors any request can get and the validation error of a request
 * nobody knows, and the closed set of them all, so a client can handle every error in one place.
 */
final readonly class ErrorSet implements DocumentTransformer
{
    public function __construct(
        private AppAnswers $answers,
        private ErrorSchemas $schemas,
        private SchemaFromSample $schemaFromSample,
        private ValidationSamples $validationSamples,
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

        $validation = [];
        $status = null;
        foreach ($this->validationSamples->ofAnyRequest() as $exception) {
            $answer = $this->answers->to($exception, 'POST', $path);

            if ($answer !== null) {
                $status ??= $answer['status'];
                $validation[] = ValidationSchema::perInput($this->schemaFromSample->of($answer['body']), null);
            }
        }

        if ($status !== null) {
            $this->schemas->add(SchemaFromSample::merge($validation), null, $status);
        }

        foreach ($this->schemas->all() as $name => $schema) {
            $document->components->removeSchema($name);
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
