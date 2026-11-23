# igne-agency/laravel-scramble-extensions

Extensions for [Scramble](https://scramble.dedoc.co) that fit any Laravel API. Each one tells Scramble something about the application it cannot read by itself. None of them decides what a response looks like, so they work with Laravel's default errors and with any error format another package documents.

## Requirements

- PHP 8.4
- Laravel 12 or 13
- dedoc/scramble 0.13.52 or a later 0.13 release

## Install

```bash
composer require igne-agency/laravel-scramble-extensions
```

Every extension is on by default. To turn one off, publish the config:

```bash
php artisan vendor:publish --tag=scramble-extensions-config
```

## The extensions

| Config key | Class | What it adds to the document |
|---|---|---|
| `called_methods` | `ExceptionsOfCalledMethods` | The errors thrown by the methods an action calls. Scramble only reads the action; this follows its calls into the application's own code, all the way down (controller, service, repository). |
| `middleware` | `ExceptionsOfMiddleware` | The errors the middleware of a route throws before the action runs: a throttle, a check of the application's own. |
| `pagination` | `PaginatedResponses` | Named schemas for a paginated list (`PaginationLinks`, `PaginationMeta`, `Paginated<Resource>`) instead of one inline schema per endpoint, so a client generates reusable types. |

The first two add exceptions to what Scramble knows an action throws. Scramble, or a package such as `igne-agency/laravel-problem-details`, then documents the error responses from that list.

## Limits

- Only a call whose target is known from the code is followed: a typed property or argument, a static call, `new`, `$this`. What runs behind an interface, the container (`app(Service::class)`), an event or a job is not. Such a method declares its exceptions with `@throws`.
- The framework and other packages are not read; they document their exceptions with `@throws`.
- A middleware that answers with a response instead of throwing is not seen.
- A middleware that throws through another class declares it with `@throws` on `handle()`.

## Development

```bash
composer install
composer test
```
