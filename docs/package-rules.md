# Rules for a package that exposes API routes

A package that follows these rules is plain Laravel. It needs no error package and no documentation tool, and it works the same without them. In return, whatever shapes the errors of an application and whatever documents its API can treat the package exactly like the application's own code.

The rules are parked in this repository until they have a home of their own (the project template, or a standards repository).

## 1. Throw, never render

Every error is an exception. Use the framework's own where one exists (validation, authentication, authorization, model not found); otherwise a class of the package that extends one of Symfony's HTTP exceptions. No `render()` method, no `Responsable`, and no error response returned from a middleware or a controller. The exception handler of the application decides the shape and the reporting.

```php
// Good
throw new TwoFactorRequiredException;

// Bad: the handler of the application never sees these
return response()->json(['message' => '...', 'code' => 'two_factor_required'], 403);

final class TwoFactorRequiredException extends RuntimeException implements Responsable { ... }
```

## 2. One class per error

Name the class after the error, make it constructible without arguments, and let its parent class tell the status: Symfony's exception of that status (`AccessDeniedHttpException` for a 403, `LockedHttpException` for a 423), plain `HttpException` only when Symfony has none. The message must be safe to show. The first paragraph of the class docblock says when the error happens and what the client does.

The class name is public API: an application may name the error after it (`TwoFactorRequiredException` becomes `two_factor_required`). Renaming it is a breaking change.

```php
/**
 * The user has two-factor authentication, but did not verify it on this device.
 * They pass the two-factor challenge to continue.
 */
final class TwoFactorRequiredException extends AccessDeniedHttpException
{
    public function __construct()
    {
        parent::__construct(__('authentication::messages.two_factor_required'));
    }
}
```

## 3. Declare what you throw

Put `@throws` on every controller action and on a middleware's `handle()`. Code under `vendor/` is partly read by its signatures and PHPDoc only, so an undeclared exception is an undocumented error.

## 4. Own the routes you change

A route of the package points at a controller of the package, with a Form Request in and a Resource (or a typed or `@response` return) out, and a PHPDoc summary.

A route may point straight at the controller of a library the package builds on, such as Laravel Fortify, as long as the package changes nothing about what that route accepts and answers. Such a controller is read as it is: its Form Request, the response class bound to its contract, the actions it calls.

Where the package adds an input or changes an answer, the route gets a controller of the package, which hands the request over. That is no wrapper for the sake of documentation: the package answers something else there, so it says so.

```php
/**
 * Log in.
 *
 * @response array{two_factor: bool, token?: string}
 *
 * @throws ValidationException
 */
public function store(LoginRequest $request): mixed
{
    return $this->fortify->store($request);
}
```

A status without a body is stated the same way: `@response \Illuminate\Http\Response<'', 204>`.

Never ship a hand-written spec of the routes. The documentation rules read code: a spec that says `email: string` holds no validation rules to document.

## 5. Use the framework's middleware unchanged

`auth`, `throttle`, `password.confirm` and the rest, as Laravel ships them. A package that alters behaviour adapts to them.

## 6. No rendering code, no documentation code

Nothing in `src/` references an error package or a documentation tool. An architecture test keeps it that way:

```php
arch('the package uses no error package and no documentation tool')
    ->expect('Vendor\Package')
    ->not->toUse(['Dedoc\Scramble', 'Igne\ProblemDetails', 'Igne\ScrambleExtensions']);
```

## 7. A group middleware says where it applies

A middleware that sits on a whole group but only refuses some of its routes adds one static method. There is no interface to implement.

```php
/**
 * @return list<class-string<Throwable>>
 */
public static function throwsFor(Route $route): array
{
    return self::guards($route) ? [TwoFactorRequiredException::class] : [];
}
```

Without it, `@throws` on `handle()` counts for every route the middleware is on.

## Tests every such package has

- The architecture test above, plus: every exception extends an HTTP exception and does not render itself, and every route points at a controller of the package or of the library it builds on.
- A snapshot of the name and status of each error, so a rename fails a test.
- One error followed through every combination: the package alone, with an error package, with a documentation package, with both. The other packages are `require-dev` only.
