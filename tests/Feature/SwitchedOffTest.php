<?php

declare(strict_types=1);

beforeEach(function () {
    $this->document = $this->document();
});

it('leaves the exceptions of called methods out when the extension is off', function () {
    $responses = $this->document['paths']['/fixtures/articles/{article}/archive']['post']['responses'];

    expect($responses)->not->toHaveKey('409');
});

it('leaves the exceptions of middleware out when the extension is off', function () {
    $store = $this->document['paths']['/fixtures/articles']['post']['responses'];
    $drafts = $this->document['paths']['/fixtures/drafts']['post']['responses'];

    // Not asserted for the throttle: Scramble documents its 429 by itself from 0.13.53 on.
    expect($store)->not->toHaveKey('403')
        ->and($drafts)->not->toHaveKey('403');
});

it('leaves a paginated list inline when the extension is off', function () {
    $schema = $this->document['paths']['/fixtures/articles']['get']['responses']['200']['content']['application/json']['schema'];

    expect($schema)->toHaveKey('properties')
        ->and($this->document['components']['schemas'])->not->toHaveKey('PaginationMeta');
});
