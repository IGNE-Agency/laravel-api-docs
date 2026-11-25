<?php

declare(strict_types=1);

// No package and no code gives the errors of this application a shape: they are Laravel's, and so is the document.

beforeEach(function () {
    $this->document = $this->document();
    $this->schemas = $this->document['components']['schemas'];
});

it('documents an error as Laravel answers it: a message, one schema per status', function () {
    $response = $this->document['paths']['/fixtures/articles/{article}']['put']['responses'];

    expect($response['423']['content'])->toBe(['application/json' => ['schema' => ['$ref' => '#/components/schemas/LockedError']]])
        ->and($this->schemas['LockedError']['properties'])->toBe(['message' => ['type' => 'string']])
        ->and($response['401']['content']['application/json']['schema'])->toBe(['$ref' => '#/components/schemas/UnauthorizedError']);
});

it('documents a status the code aborts with as Laravel answers it', function () {
    $responses = $this->document['paths']['/fixtures/articles/{article}']['delete']['responses'];

    expect(array_keys($responses))->toBe([204, 409])
        ->and($responses['409']['content'])->toBe(['application/json' => ['schema' => ['$ref' => '#/components/schemas/ConflictError']]])
        ->and($this->schemas['ConflictError']['properties'])->toBe(['message' => ['type' => 'string']]);
});

it('documents the validation error of a request with its inputs, as Laravel lists them', function () {
    $schema = $this->schemas['StoreArticleValidationError'];

    expect($this->document['paths']['/fixtures/articles/validated']['post']['responses']['422']['content']['application/json']['schema'])
        ->toBe(['$ref' => '#/components/schemas/StoreArticleValidationError'])
        ->and(array_keys($schema['properties']))->toBe(['message', 'errors'])
        ->and(array_keys($schema['properties']['errors']['properties']))->toBe(['title', 'rating'])
        ->and($schema['properties']['errors']['properties']['title'])->toBe(['type' => 'array', 'items' => ['type' => 'string']])
        // An item of a list has no name of its own.
        ->and($schema['properties']['errors']['additionalProperties'])->toBe(['type' => 'array', 'items' => ['type' => 'string']]);
});

it('has no codes to tell errors apart, because Laravel answers none', function () {
    expect($this->schemas)->toHaveKey('ApiError')->not->toHaveKey('ApiErrorCode');
});

it('matches what the application really answers', function () {
    $this->putJson('/api/fixtures/articles/1')->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated.']);
    $this->postJson('/api/fixtures/articles/validated', [])->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['title']]);
});
