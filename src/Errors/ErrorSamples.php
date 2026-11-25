<?php

declare(strict_types=1);

namespace Igne\ScrambleExtensions\Errors;

use Dedoc\Scramble\Infer;
use Dedoc\Scramble\Support\ExceptionToResponseExtensions\HttpExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\Type\ObjectType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

/**
 * Builds a real exception of every kind an operation can throw, to ask the application what it
 * answers it with:
 *
 * - an error with a class of its own is built without arguments, which is how such a class is
 *   written;
 * - the framework's own exceptions are built as the framework throws them;
 * - a validation error is built as the worst case of the request: every rule of every input has
 *   failed, so the answer shows everything a client can get back.
 *
 * An exception that cannot be built this way, or that is no HTTP error, gives null: its response
 * is left as Scramble documents it.
 */
final readonly class ErrorSamples
{
    /**
     * The name of the input the worst case of any request is built on: an input without a name.
     */
    public const string ANY_INPUT = 'input';

    /**
     * The headers Laravel's throttle sends with its 429.
     */
    private const string LOCALE = 'en';

    private const array THROTTLE_HEADERS = ['Retry-After' => 60, 'X-RateLimit-Limit' => 60, 'X-RateLimit-Remaining' => 0];

    public function __construct(
        private Infer $infer,
        private TypeTransformer $openApiTransformer,
    ) {}

    /**
     * @param  array<string, list<array{0: string, 1: list<mixed>}>>  $rulesByInput  the rules of the request: input => [rule name or class, parameters]
     * @return list<Throwable>  the answers to all of them together show what a client can get back
     */
    public function of(ObjectType $type, array $rulesByInput = []): array
    {
        $class = $type->name;

        if (! class_exists($class)) {
            return [];
        }

        if (is_a($class, ValidationException::class, true)) {
            return $this->ofValidation($rulesByInput === [] ? $this->everyRule() : $rulesByInput);
        }

        try {
            $exception = match (true) {
                is_a($class, AuthenticationException::class, true) => new AuthenticationException,
                is_a($class, AuthorizationException::class, true) => new AuthorizationException,
                is_a($class, RecordsNotFoundException::class, true) => new ModelNotFoundException,
                is_a($class, TokenMismatchException::class, true) => new TokenMismatchException('CSRF token mismatch.'),
                is_a($class, ThrottleRequestsException::class, true) => new ThrottleRequestsException('Too Many Attempts.', null, self::THROTTLE_HEADERS),
                $class === TooManyRequestsHttpException::class => new TooManyRequestsHttpException(60),
                $class === UnauthorizedHttpException::class => new UnauthorizedHttpException('Bearer'),
                $class === MethodNotAllowedHttpException::class => new MethodNotAllowedHttpException(['GET']),
                $class === HttpException::class => $this->ofLiteralStatus($type),
                is_a($class, HttpExceptionInterface::class, true) => $this->withoutArguments($class),
                default => null,
            };
        } catch (Throwable) {
            return [];
        }

        return $exception === null ? [] : [$exception];
    }

    /**
     * The errors Laravel can answer any request with, whatever its endpoint documents.
     *
     * @return list<Throwable>
     */
    public function ofAnyRequest(): array
    {
        return [
            new NotFoundHttpException,
            new MethodNotAllowedHttpException(['GET']),
            new PostTooLargeException,
            new TokenMismatchException('CSRF token mismatch.'),
            new RuntimeException,
            new HttpException(503),
        ];
    }

    /**
     * The validation error of a request nobody knows: one unnamed input that failed every rule
     * Laravel has a message for.
     *
     * @return list<ValidationException>
     */
    public function ofAnyValidation(): array
    {
        return $this->ofValidation($this->everyRule());
    }

    /**
     * The name of a validation failure as the answer reports it: of an input that names an item of
     * a list (`tags.*`), its first item.
     */
    public static function attribute(string $input): string
    {
        return str_replace('*', '0', $input);
    }

    /**
     * Two validation errors of one request, whose answers together show everything a client can
     * get back: every rule of every input has failed, and every input has a message that was added
     * without a rule (`ValidationException::withMessages()`, an `after()` hook).
     *
     * @param  array<string, list<array{0: string, 1: list<mixed>}>>  $rulesByInput
     * @return list<ValidationException>
     */
    private function ofValidation(array $rulesByInput): array
    {
        $everyRuleFailed = $this->validator();
        $messagesByHand = $this->validator();

        foreach ($rulesByInput as $input => $rules) {
            $attribute = self::attribute((string) $input);
            $failed = [];

            foreach ($rules as [$rule, $parameters]) {
                $name = str_contains($rule, '\\') ? $rule : self::ruleName($rule);

                if (isset($failed[$name])) {
                    continue;
                }

                // A rule without a message never fails by itself: `nullable`, `sometimes`, `bail`.
                if (str_contains($rule, '\\') || self::hasMessage($name)) {
                    $everyRuleFailed->fail($attribute, $name, array_values(array_map(strval(...), array_filter($parameters, is_scalar(...)))));
                    $failed[$name] = true;
                }
            }

            $messagesByHand->failByHand($attribute);
        }

        return array_values(array_filter(
            [new ValidationException($everyRuleFailed), new ValidationException($messagesByHand)],
            static fn (ValidationException $exception): bool => $exception->validator->errors()->isNotEmpty(),
        ));
    }

    /**
     * A validator whose failures are set by hand instead of found by validating.
     */
    private function validator(): Validator
    {
        return new class(app('translator'), [], []) extends Validator
        {
            /**
             * A rule failed: a string rule by its name, a rule object by its class. The message is
             * one of its own, because the validator keeps a message of an input only once, and an
             * answer tells the rules of an input apart by their messages.
             *
             * @param  list<string>  $parameters
             */
            public function fail(string $attribute, string $rule, array $parameters): void
            {
                $this->messages ?? $this->passes();
                $this->failedRules[$attribute][$rule] = $parameters;
                $this->messages->add($attribute, "The {$attribute} field failed the {$rule} rule.");
            }

            /**
             * A message added without a rule.
             */
            public function failByHand(string $attribute): void
            {
                $this->messages ?? $this->passes();
                $this->messages->add($attribute, "The {$attribute} field is invalid.");
            }
        };
    }

    /**
     * Every rule Laravel has a message for, on one unnamed input.
     *
     * @return array<string, list<array{0: string, 1: list<mixed>}>>
     */
    private function everyRule(): array
    {
        $lines = Lang::get('validation', [], self::LOCALE);
        $rules = [];

        foreach (is_array($lines) ? $lines : [] as $rule => $line) {
            // The group also holds the names of attributes and the messages of single inputs.
            if (is_string($rule) && ! in_array($rule, ['attributes', 'custom', 'values'], true)) {
                $rules[] = [$rule, []];
            }
        }

        return [self::ANY_INPUT => $rules];
    }

    /**
     * Whether Laravel has a message for the rule, which is what makes a rule one that can fail. The
     * English group is asked first: the framework ships it complete, a translation may lag behind.
     */
    private static function hasMessage(string $rule): bool
    {
        $line = 'validation.'.Str::snake($rule);

        return Lang::has($line, self::LOCALE) || Lang::has($line);
    }

    /**
     * The name the validator knows a rule by: `max` and `required_without_all` in StudlyCase, an
     * alias by the rule it stands for.
     */
    private static function ruleName(string $rule): string
    {
        return match ($name = Str::studly($rule)) {
            'Int' => 'Integer',
            'Bool' => 'Boolean',
            default => $name,
        };
    }

    /**
     * `abort(409)` and `new HttpException(409)`: the status Scramble reads from the code.
     */
    private function ofLiteralStatus(ObjectType $type): ?HttpException
    {
        $status = (new HttpExceptionToResponseExtension($this->infer, $this->openApiTransformer, $this->openApiTransformer->getComponents()))->toResponse($type)?->code;

        return is_int($status) ? new HttpException($status) : null;
    }

    /**
     * @param  class-string  $class
     */
    private function withoutArguments(string $class): ?Throwable
    {
        $reflection = new ReflectionClass($class);

        if (! $reflection->isInstantiable() || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
            return null;
        }

        $exception = $reflection->newInstance();

        return $exception instanceof Throwable ? $exception : null;
    }
}
