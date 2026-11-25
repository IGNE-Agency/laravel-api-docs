# igne-agency/laravel-scramble-extensions

The documentation rules of an IGNE API, as extensions for [Scramble](https://scramble.dedoc.co). Nobody writes documentation: the rules read the code of the application and of every package that registers routes, and produce the document.

Each rule applies to every route of the document, whoever registered it. A package registers and configures nothing.

## Requirements

- PHP 8.4
- Laravel 12 or 13
- dedoc/scramble 0.13.52 or a later 0.13 release

## Install

```bash
composer require igne-agency/laravel-scramble-extensions
```

Every rule is on by default. To turn one off or to name things differently, publish the config:

```bash
php artisan vendor:publish --tag=scramble-extensions-config
```

## The rules

| Config key | What it adds to the document |
|---|---|
| `called_methods` | The errors thrown by the methods an action calls, all the way down (controller, service, repository). The application's code is followed, and a package inside itself. |
| `bound_responses` | The answer of a controller that answers through a response contract (`return app(LoginResponse::class)`, as Laravel Fortify does): the contract is followed to the class bound to it. A call to an action class (`$action($user)`) is followed too. |
| `packages` | A package with a controller on a route is read like the application, so its Resources keep the types of the values they take from other classes of the package. |
| `middleware` | The errors the middleware of a route throws: a throttle, a check of the application or of a package, the CSRF 419, the model-binding 404. Kept per route. |
| `pagination` | Named schemas for a paginated list (`PaginationLinks`, `PaginationMeta`, `Paginated<Resource>`). |
| `csrf` | The `X-XSRF-TOKEN` header on every operation whose CSRF token Laravel checks. |
| `security` | How a client authenticates on a route behind `auth`: a bearer token, the session cookie, or either. |
| `errors` | The error responses, as the application really answers them. |

## Error responses: the application is asked

Nothing describes the shape of an error to this package. For each exception an operation can throw:

1. a real exception is built: a class of its own without arguments, the framework's exceptions as the framework throws them, a validation error as the worst case of the request;
2. the application's own exception handler renders it, with debug off;
3. the answer is written down as a schema.

So an application that answers Laravel's `{ "message" }` is documented that way, and one that answers RFC 9457 Problem Details that way, with no setting to change and no link to whatever gives the errors their shape.

- A value that looks like an identifier (`two_factor_required`) is a fixed value, unless it is the value of a member listed in `errors.free_text`.
- An answer with one fixed value of its own is named after it (`TwoFactorRequiredError`); one without is named after its status.
- Several errors of one status are a `oneOf`, discriminated by the member that is fixed and different in each.
- The description of an error is the first paragraph of the docblock of its exception class.
- `ApiError` and `ApiErrorCode` are the closed set of every error and of the values that tell them apart.
- An exception that cannot be built without arguments keeps the response Scramble documents by itself.

## Rules for packages

A package that exposes routes is documented from its code, like the application. [Rules for a package that exposes API routes](docs/package-rules.md) lists what that asks of it. Nothing in such a package refers to this one. The one thing it may declare is where a group middleware applies:

```php
public static function throwsFor(Route $route): array
```

## Testing

`Igne\ScrambleExtensions\Testing\AssertsDocumentedResponses` asserts that a real response is what the document says the operation answers: its status, its content type and a body that fits the documented schema.

```php
$this->assertDocumented($this->getJson('/api/articles/1'), 'get', '/articles/{article}');
```

## Limits

- Only a call whose target is known from the code is followed: a typed property or argument, a static call, `new`, `$this`. What runs behind an interface, the container, an event or a job declares its exceptions with `@throws`.
- A middleware that answers with a response instead of throwing is not seen.
- The error schemas are inferred from real answers. They carry no descriptions of the members of an error format, and which value is fixed is decided by its looks.

## Development

```bash
composer install
composer test
```

The tests run without any error package installed: one application answers Laravel's errors, another a format made up for the tests.
