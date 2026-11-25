<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Errors;

use Dedoc\Scramble\Infer;
use Dedoc\Scramble\Support\ExceptionToResponseExtensions\HttpExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\Type\ObjectType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Session\TokenMismatchException;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

/**
 * Builds a real exception of every kind an operation can throw, to ask the application what it
 * answers it with:
 *
 * - an error with a class of its own is built without arguments, which is how such a class is
 *   written;
 * - the framework's own exceptions are built as the framework throws them.
 *
 * A validation error is built from the rules of its request, by
 * {@see \Igne\ApiDocs\Validation\ValidationSamples}.
 *
 * An exception that cannot be built this way, or that is no HTTP error, gives null: its response
 * is left as Scramble documents it.
 */
final readonly class ErrorSamples
{
    /**
     * The headers Laravel's throttle sends with its 429.
     */
    private const array THROTTLE_HEADERS = ['Retry-After' => 60, 'X-RateLimit-Limit' => 60, 'X-RateLimit-Remaining' => 0];

    public function __construct(
        private Infer $infer,
        private TypeTransformer $openApiTransformer,
    ) {}

    /**
     * @return list<Throwable>
     */
    public function of(ObjectType $type): array
    {
        $class = $type->name;

        if (! class_exists($class)) {
            return [];
        }

        try {
            $exception = match (true) {
                is_a($class, AuthenticationException::class, true) => new AuthenticationException,
                is_a($class, AuthorizationException::class, true) => new AuthorizationException,
                is_a($class, RecordsNotFoundException::class, true) => new ModelNotFoundException,
                is_a($class, TokenMismatchException::class, true) => new TokenMismatchException('CSRF token mismatch.'),
                is_a($class, ThrottleRequestsException::class, true) => new ThrottleRequestsException('Too Many Attempts.', null, self::THROTTLE_HEADERS),
                $class === TooManyRequestsHttpException::class => new TooManyRequestsHttpException(60),
                $class === UnauthorizedHttpException::class => new UnauthorizedHttpException('Bearer'),
                $class === MethodNotAllowedHttpException::class => new MethodNotAllowedHttpException(['GET']),
                $class === HttpException::class => $this->ofLiteralStatus($type),
                is_a($class, HttpExceptionInterface::class, true) => $this->withoutArguments($class),
                default => null,
            };
        } catch (Throwable) {
            return [];
        }

        return $exception === null ? [] : [$exception];
    }

    /**
     * The errors Laravel can answer any request with, whatever its endpoint documents.
     *
     * @return list<Throwable>
     */
    public static function ofAnyRequest(): array
    {
        return [
            new NotFoundHttpException,
            new MethodNotAllowedHttpException(['GET']),
            new PostTooLargeException,
            new TokenMismatchException('CSRF token mismatch.'),
            new RuntimeException,
            new HttpException(503),
        ];
    }

    /**
     * `abort(409)` and `new HttpException(409)`: the status Scramble reads from the code.
     */
    private function ofLiteralStatus(ObjectType $type): ?HttpException
    {
        $extension = new HttpExceptionToResponseExtension($this->infer, $this->openApiTransformer, $this->openApiTransformer->getComponents());
        $status = $extension->toResponse($type)?->code;

        return is_int($status) ? new HttpException($status) : null;
    }

    /**
     * @param  class-string  $class
     */
    private function withoutArguments(string $class): ?Throwable
    {
        $reflection = new ReflectionClass($class);

        if (! $reflection->isInstantiable() || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
            return null;
        }

        $exception = $reflection->newInstance();

        return $exception instanceof Throwable ? $exception : null;
    }
}
