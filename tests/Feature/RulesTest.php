<?php

declare(strict_types=1);

function headersOf(array $operation): array
{
    return array_column(array_filter($operation['parameters'] ?? [], static fn (array $parameter): bool => $parameter['in'] === 'header'), 'name');
}

beforeEach(function () {
    $this->document = $this->document();
});

it('documents the errors a middleware declares for a route on that route only', function () {
    expect($this->document['paths']['/fixtures/lounge']['get']['responses'])->toHaveKey('423')
        ->and(array_keys($this->document['paths']['/fixtures/lobby']['get']['responses']))->toBe([204]);
});

it('documents the 404 of the route model binding only on a route that has a parameter to bind', function () {
    expect($this->document['paths']['/fixtures/pings/{ping}']['get']['responses'])->toHaveKey('404')
        ->and($this->document['paths']['/fixtures/pings']['get']['responses'])->not->toHaveKey('404');
});

it('documents the XSRF header and the 419 on an operation that changes state on a route with the CSRF check', function () {
    $operation = $this->document['paths']['/fixtures/profile']['put'];

    expect(headersOf($operation))->toBe(['X-XSRF-TOKEN'])
        ->and($operation['responses'])->toHaveKey('419');
});

it('documents neither on an operation that only reads, on an excepted path or on a route without the check', function (string $path, string $method) {
    $operation = $this->document['paths'][$path][$method];

    expect(headersOf($operation))->toBe([])
        ->and($operation['responses'])->not->toHaveKey('419');
})->with([
    'reads' => ['/fixtures/profile', 'get'],
    'excepted' => ['/fixtures/webhook', 'post'],
    'no check' => ['/fixtures/articles', 'post'],
]);

it('documents how a client authenticates on a route behind auth, and nothing on a public route', function () {
    expect($this->document['paths']['/fixtures/articles/{article}']['put']['security'])->toBe([['session' => []]])
        ->and($this->document['components']['securitySchemes'])->toHaveKey('session')
        ->and($this->document['paths']['/fixtures/articles']['get'])->not->toHaveKey('security');
});

it('writes the same document every time', function () {
    expect($this->document())->toBe($this->document);
});
