<?php

declare(strict_types=1);

namespace Igne\ScrambleExtensions\Tests\Fixtures;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * An application that gives its errors a shape of its own, which this package has never heard of:
 * `{ code, message, fields }` as `application/vnd.fixture+json`. Whatever does that in a real
 * application (a package, `bootstrap/app.php`) is nothing this package refers to.
 */
final class CodedErrorsServiceProvider extends ServiceProvider
{
    public const string CONTENT_TYPE = 'application/vnd.fixture+json';

    public function boot(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        if (! $handler instanceof Handler) {
            return;
        }

        $handler->renderable(static function (Throwable $exception, Request $request): ?Response {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = match (true) {
                $exception instanceof ValidationException => $exception->status,
                $exception instanceof AuthenticationException => 401,
                $exception instanceof TokenMismatchException => 419,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };

            $ownClass = str_starts_with($exception::class, 'Igne\\');

            return response()->json(array_filter([
                'code' => $ownClass
                    ? Str::snake(Str::beforeLast(class_basename($exception), 'Exception'))
                    : Str::slug($status === 419 ? 'Page Expired' : (Response::$statusTexts[$status] ?? 'Error'), '_'),
                'message' => $ownClass ? $exception->getMessage() : null,
                'fields' => $exception instanceof ValidationException
                    ? array_map(static fn (array $rules): array => array_map(Str::snake(...), array_keys($rules)), $exception->validator->failed())
                    : null,
            ]), $status, ['Content-Type' => self::CONTENT_TYPE]);
        });
    }
}
