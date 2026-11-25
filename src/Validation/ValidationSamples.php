<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Validation;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Builds the validation errors of a request as its worst case, to ask the application what it
 * answers them with: every rule of every input has failed, so the answer shows everything a client
 * can get back.
 */
final readonly class ValidationSamples
{
    /**
     * The name of the input the worst case of any request is built on: an input without a name.
     */
    public const string ANY_INPUT = 'input';

    private const string LOCALE = 'en';

    /**
     * Two validation errors of one request, whose answers together show everything a client can
     * get back: every rule of every input has failed, and every input has a message that was added
     * without a rule (`ValidationException::withMessages()`, an `after()` hook).
     *
     * @param  array<string, list<array{0: string, 1: list<mixed>}>>  $rulesByInput  input => [rule name or class, parameters]
     * @return list<ValidationException>
     */
    public function ofRequest(array $rulesByInput): array
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
     * The validation error of a request nobody knows: one unnamed input that failed every rule
     * Laravel has a message for.
     *
     * @return list<ValidationException>
     */
    public function ofAnyRequest(): array
    {
        return $this->ofRequest($this->everyRule());
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
}
