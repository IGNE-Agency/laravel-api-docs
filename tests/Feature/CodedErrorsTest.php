<?php

declare(strict_types=1);

use Igne\ApiDocs\Tests\Fixtures\CodedErrorsServiceProvider;

// The application answers its errors in a format this package has never heard of. Nothing describes it: the
// package asks the application, and documents what comes back.

beforeEach(function () {
    $this->document = $this->document();
    $this->schemas = $this->document['components']['schemas'];
});

it('documents an error in the format of the application, named after the fixed value that tells it apart', function () {
    $response = $this->document['paths']['/fixtures/articles/{article}']['put']['responses']['423'];

    expect($response['content'])->toBe([CodedErrorsServiceProvider::CONTENT_TYPE => ['schema' => ['$ref' => '#/components/schemas/ArticleLockedError']]])
        ->and($this->schemas['ArticleLockedError']['properties'])->toBe([
            'code' => ['type' => 'string', 'const' => 'article_locked'],
            'message' => ['type' => 'string'],
        ])
        // The description of the error is the docblock of its exception class.
        ->and($this->schemas['ArticleLockedError']['description'])->toBe('The article is being edited by someone else. Try again once they are done.');
});

it('tells the errors of one status apart by the member that is fixed and different in each', function () {
    $schema = $this->document['paths']['/fixtures/articles']['post']['responses']['403']['content'][CodedErrorsServiceProvider::CONTENT_TYPE]['schema'] ?? null;

    expect($this->schemas['ApiErrorCode']['enum'])->toContain('article_locked', 'unauthorized', 'not_found', 'unprocessable_content')
        ->and($this->schemas['ApiError']['discriminator']['propertyName'])->toBe('code')
        ->and($schema)->toBe(['$ref' => '#/components/schemas/ForbiddenError']);
});

it('documents the validation error of a request with the rules each input can fail, read from the real answer', function () {
    $fields = $this->schemas['StoreArticleValidationError']['properties']['fields'];

    expect($fields['properties']['title']['items']['enum'])->toEqualCanonicalizing(['required', 'string', 'max'])
        ->and($fields['properties']['rating']['items']['enum'])->toEqualCanonicalizing(['integer', 'between'])
        ->and($fields['additionalProperties']['items'])->toBe(['type' => 'string', 'const' => 'string']);
});

it('matches what the application really answers', function () {
    $this->putJson('/api/fixtures/articles/1')
        ->assertStatus(401)
        ->assertHeader('Content-Type', CodedErrorsServiceProvider::CONTENT_TYPE)
        ->assertExactJson(['code' => 'unauthorized']);
});
