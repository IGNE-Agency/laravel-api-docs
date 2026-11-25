<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Errors;

use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\Type\ObjectType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Session\TokenMismatchException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

/**
 * Builds a real exception for an error an operation can throw, to ask the application what it
 * answers. A validation error is built by {@see \Igne\ApiDocs\Validation\ValidationSamples}.
 */
final class ErrorSamples
{
    /**
     * What Laravel's throttle sends with its 429.
     */
    private const array THROTTLE_HEADERS = ['Retry-After' => 60, 'X-RateLimit-Limit' => 60, 'X-RateLimit-Remaining' => 0];

    /**
     * Empty when the exception cannot be built or is no HTTP error: Scramble's own response then stays.
     *
     * @return list<Throwable>
     */
    public static function of(ObjectType $type, TypeTransformer $openApiTransformer): array
    {
        $class = $type->name;

        try {
            $exception = match (true) {
                // The framework's exceptions, as the framework throws them.
                is_a($class, AuthenticationException::class, true) => new AuthenticationException,
                is_a($class, AuthorizationException::class, true) => new AuthorizationException,
                is_a($class, RecordsNotFoundException::class, true) => new ModelNotFoundException,
                is_a($class, TokenMismatchException::class, true) => new TokenMismatchException('CSRF token mismatch.'),
                is_a($class, ThrottleRequestsException::class, true) => new ThrottleRequestsException('Too Many Attempts.', null, self::THROTTLE_HEADERS),
                $class === TooManyRequestsHttpException::class => new TooManyRequestsHttpException(60),
                $class === UnauthorizedHttpException::class => new UnauthorizedHttpException('Bearer'),
                $class === MethodNotAllowedHttpException::class => new MethodNotAllowedHttpException(['GET']),
                $class === HttpException::class => self::ofLiteralStatus($type, $openApiTransformer),
                // An error with a class of its own is built without arguments; one that needs them throws here.
                is_a($class, HttpExceptionInterface::class, true) => new $class,
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
    private static function ofLiteralStatus(ObjectType $type, TypeTransformer $openApiTransformer): ?HttpException
    {
        $response = $openApiTransformer->toResponse($type);
        $status = $response instanceof Response ? $response->code : null;

        return is_int($status) ? new HttpException($status) : null;
    }
}
