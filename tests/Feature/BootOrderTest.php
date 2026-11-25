<?php

declare(strict_types=1);

use Igne\ScrambleExtensions\ScrambleExtensionsServiceProvider;
use Igne\ScrambleExtensions\Tests\Fixtures\CodedErrorsServiceProvider;

// The provider that gives the errors their shape boots before this package here, and after it in
// CodedErrorsTest. The document must not depend on which comes first.

it('writes the same errors whichever provider boots first', function () {
    $providers = array_keys(app()->getLoadedProviders());

    expect(array_search(CodedErrorsServiceProvider::class, $providers, true))
        ->toBeLessThan(array_search(ScrambleExtensionsServiceProvider::class, $providers, true));

    $schemas = $this->document()['components']['schemas'];

    expect($schemas['ArticleLockedError']['properties']['code'])->toBe(['type' => 'string', 'const' => 'article_locked'])
        ->and($schemas['ApiErrorCode']['enum'])->toContain('article_locked')
        ->and($schemas['StoreArticleValidationError']['properties']['fields']['properties']['title']['items']['enum'])->toEqualCanonicalizing(['required', 'string', 'max']);
});
