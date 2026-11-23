<?php

declare(strict_types=1);

beforeEach(function () {
    $this->document = $this->document();
});

it('documents the exceptions thrown by the methods an action calls, however deep', function () {
    $responses = $this->document['paths']['/fixtures/articles/{article}/archive']['post']['responses'];

    expect($responses)->toHaveKey('409');
});

it('documents the exceptions the middleware of a route throws', function () {
    $responses = $this->document['paths']['/fixtures/articles']['post']['responses'];

    expect($responses)->toHaveKeys(['403', '429']);
});

it('documents the exceptions of the middleware of a route whose action is a closure', function () {
    $responses = $this->document['paths']['/fixtures/drafts']['post']['responses'];

    expect($responses)->toHaveKey('403');
});

it('documents a route without such middleware or calls with its own responses only', function () {
    $responses = $this->document['paths']['/fixtures/articles']['get']['responses'];

    expect(array_keys($responses))->toBe([200]);
});

it('documents a paginated list as named schemas', function () {
    $schema = $this->document['paths']['/fixtures/articles']['get']['responses']['200']['content']['application/json']['schema'];
    $schemas = $this->document['components']['schemas'];

    expect($schema)->toBe(['$ref' => '#/components/schemas/PaginatedArticleResource'])
        ->and($schemas)->toHaveKeys(['PaginatedArticleResource', 'PaginationLinks', 'PaginationMeta'])
        ->and($schemas['PaginatedArticleResource']['properties']['data']['items'])->toBe(['$ref' => '#/components/schemas/ArticleResource'])
        ->and($schemas['PaginatedArticleResource']['properties']['links'])->toBe(['$ref' => '#/components/schemas/PaginationLinks'])
        ->and($schemas['PaginatedArticleResource']['properties']['meta'])->toBe(['$ref' => '#/components/schemas/PaginationMeta']);
});
