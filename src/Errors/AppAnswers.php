<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Errors;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Asks the application what it answers an error with: its own exception handler renders the
 * exception for a JSON request of the operation. Debug is off meanwhile, so the answer is the one
 * of production.
 *
 * @phpstan-type Answer array{status: int, contentType: string, headers: array<string, string>, body: array<string, mixed>}
 */
final class AppAnswers
{
    /**
     * What the global middleware of the application add to the log context of a request (a trace
     * id, for example), learnt once by sending one request through them.
     *
     * @var array{data: array<string, mixed>, hidden: array<string, mixed>}|null
     */
    private ?array $requestContext = null;

    public function __construct(
        private readonly Application $app,
        private readonly ExceptionHandler $handler,
    ) {}

    /**
     * @return Answer|null  null when the answer is no JSON object
     */
    public function to(Throwable $exception, string $method, string $uri): ?array
    {
        $request = $this->request($method, $uri);
        $context = $this->requestContext();
        $debug = config('app.debug');

        config(['app.debug' => false]);

        try {
            $response = Context::scope(fn () => $this->handler->render($request, $exception), $context['data'], $context['hidden']);
        } catch (Throwable) {
            return null;
        } finally {
            config(['app.debug' => $debug]);
        }

        $body = json_decode((string) $response->getContent(), true);

        if (! is_array($body) || $body === [] || array_is_list($body)) {
            return null;
        }

        $headers = [];
        foreach ($exception instanceof HttpExceptionInterface ? array_keys($exception->getHeaders()) : [] as $header) {
            $header = (string) $header;

            if ($response->headers->has($header)) {
                $headers[$header] = (string) $response->headers->get($header);
            }
        }

        return [
            'status' => $response->getStatusCode(),
            'contentType' => trim(explode(';', (string) $response->headers->get('Content-Type', 'application/json'))[0]),
            'headers' => $headers,
            'body' => $body,
        ];
    }

    /**
     * The answers to several exceptions, without those that are no JSON object.
     *
     * @param  list<Throwable>  $exceptions
     * @return list<Answer>
     */
    public function toEach(array $exceptions, string $method, string $uri): array
    {
        return array_values(array_filter(array_map(
            fn (Throwable $exception): ?array => $this->to($exception, $method, $uri),
            $exceptions,
        )));
    }

    private function request(string $method, string $uri): Request
    {
        $path = '/'.ltrim((string) preg_replace('/\{[^}]+\}/', '1', $uri), '/');

        return Request::create($path, strtoupper($method), server: [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ]);
    }

    /**
     * @return array{data: array<string, mixed>, hidden: array<string, mixed>}
     */
    private function requestContext(): array
    {
        if ($this->requestContext !== null) {
            return $this->requestContext;
        }

        $captured = ['data' => [], 'hidden' => []];
        $kernel = $this->app->make(Kernel::class);

        try {
            Context::scope(function () use (&$captured, $kernel): void {
                (new Pipeline($this->app))
                    ->send($this->request('GET', '/'))
                    ->through(method_exists($kernel, 'getGlobalMiddleware') ? $kernel->getGlobalMiddleware() : [])
                    ->then(function () use (&$captured): Response {
                        $captured = ['data' => Context::all(), 'hidden' => Context::allHidden()];

                        return new Response;
                    });
            });
        } catch (Throwable) {
            // The context stays empty: the answers are rendered without it.
        }

        return $this->requestContext = $captured;
    }
}
