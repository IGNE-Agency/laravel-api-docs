<?php

declare(strict_types=1);

namespace Igne\ScrambleExtensions\Errors;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\Infer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\TypeTransformer;

/**
 * Closes the document once every operation is documented: writes the schema of each error the
 * operations answer, adds the errors any request can get and the validation error of a request
 * nobody knows, and the closed set of them all, so a client can handle every error in one place.
 */
final readonly class ErrorSet implements DocumentTransformer
{
    private ErrorSamples $samples;

    public function __construct(
        Infer $infer,
        TypeTransformer $openApiTransformer,
        private AppAnswers $answers,
        private ErrorSchemas $schemas,
        private SchemaFromSample $schemaFromSample,
    ) {
        $this->samples = new ErrorSamples($infer, $openApiTransformer);
    }

    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $path = $this->schemas->anyPath;

        if ($path === null) {
            return;
        }

        foreach ($this->samples->ofAnyRequest() as $exception) {
            $answer = $this->answers->to($exception, 'GET', $path);

            if ($answer !== null) {
                $this->schemas->add($this->schemaFromSample->of($answer['body']), $exception::class, $answer['status']);
            }
        }

        $validation = [];
        $status = null;
        foreach ($this->samples->ofAnyValidation() as $exception) {
            $answer = $this->answers->to($exception, 'POST', $path);

            if ($answer !== null) {
                $status ??= $answer['status'];
                $validation[] = RenderedErrors::perInput($this->schemaFromSample->of($answer['body']), null);
            }
        }

        if ($status !== null) {
            $this->schemas->add(SchemaFromSample::merge($validation), null, $status);
        }

        foreach ($this->schemas->all() as $name => $schema) {
            $document->components->removeSchema($name);
            $document->components->addSchema($name, Schema::fromType(new RawSchema($schema)));
        }

        $closedSet = $this->schemas->closedSet();

        if ($closedSet !== null) {
            $document->components->addSchema((string) config('scramble-extensions.errors.set', 'ApiError'), Schema::fromType(new RawSchema($closedSet['errors'])));

            if ($closedSet['codes'] !== null) {
                $document->components->addSchema((string) config('scramble-extensions.errors.codes', 'ApiErrorCode'), Schema::fromType(new RawSchema($closedSet['codes'])));
            }
        }

        $this->schemas->reset();
    }
}
