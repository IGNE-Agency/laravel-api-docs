<?php

declare(strict_types=1);

use Igne\Auth\AuthenticationServiceProvider;

// The auth package needs Laravel 13; on an older Laravel it is not installed and there is nothing to test.
if (! class_exists(AuthenticationServiceProvider::class)) {
    return;
}

// igne-agency/laravel-auth is a real package that follows the rules: it holds no documentation code and depends on
// nothing of this package. These tests are the proof that its routes are documented from its code all the same. No
// error package is installed here, so its errors are documented as Laravel answers them.

beforeEach(function () {
    $this->document = $this->document();
    $this->paths = $this->document['paths'];
    $this->schemas = $this->document['components']['schemas'];
});

function statusesOf(array $operation): array
{
    return array_map(strval(...), array_keys($operation['responses']));
}

it('documents a route of the package from its controller and its Form Request', function () {
    $login = $this->paths['/auth/login']['post'];

    expect($login['summary'])->toBe('Log in')
        ->and($login['requestBody']['content']['application/json']['schema'])->toBe(['$ref' => '#/components/schemas/LoginRequest'])
        ->and(array_keys($this->schemas['LoginRequest']['properties']))->toBe(['email', 'password', 'remember', 'device_name'])
        ->and(array_keys($login['responses']['200']['content']['application/json']['schema']['properties']))->toBe(['two_factor', 'token', 'expires_at', 'challenge'])
        ->and(statusesOf($login))->toContain('422', '429');
});

it('documents a route that points straight at Fortify by reading Fortify', function () {
    // The answer is the response class bound to Fortify's contract: an empty 201, and no 200.
    expect(statusesOf($this->paths['/user/confirm-password']['post']))->toContain('201')->not->toContain('200')
        ->and($this->paths['/user/confirm-password']['post']['responses']['201'])->not->toHaveKey('content')
        // The error is thrown by an action class Fortify's controller calls.
        ->and(statusesOf($this->paths['/user/confirmed-two-factor-authentication']['post']))->toContain('422')
        // The auth package binds its own class to the contract of the logout.
        ->and(statusesOf($this->paths['/auth/logout']['post']))->toContain('204')->not->toContain('200');
});

it('documents the refusal of a group middleware only on the routes the middleware names', function () {
    expect(statusesOf($this->paths['/user/sessions']['get']))->toContain('401', '403')
        ->and(statusesOf($this->paths['/auth/login']['post']))->not->toContain('403')
        ->and(statusesOf($this->paths['/user']['get']))->not->toContain('403');
});

it('documents the errors as Laravel answers them when nothing gives them another shape', function () {
    $refusal = $this->paths['/user/sessions']['get']['responses']['403'];

    expect($refusal['content'])->toBe(['application/json' => ['schema' => ['$ref' => '#/components/schemas/ForbiddenError']]])
        ->and($this->schemas['ForbiddenError']['properties'])->toBe(['message' => ['type' => 'string']])
        ->and($this->schemas['LoginValidationError']['properties']['errors']['properties'])->toHaveKeys(['email', 'password']);
});

it('documents the session cookie, the bearer token and the XSRF header of the package routes', function () {
    $sessions = $this->paths['/user/sessions'];

    expect($sessions['get']['security'])->toBe([['bearer' => []], ['session' => []]])
        ->and(array_column($sessions['delete']['parameters'] ?? [], 'name'))->toContain('X-XSRF-TOKEN')
        ->and(statusesOf($sessions['delete']))->toContain('419')
        ->and(array_column($sessions['get']['parameters'] ?? [], 'name'))->not->toContain('X-XSRF-TOKEN');
});

it('names a paginated or listed resource of the package like one of the application', function () {
    expect($this->schemas)->toHaveKeys(['ActiveSessionResource', 'TrustedDeviceResource']);
});
