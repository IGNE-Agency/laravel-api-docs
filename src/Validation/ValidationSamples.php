<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Validation;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\ValidationRuleParser;
use Illuminate\Validation\Validator;

/**
 * Builds the validation error of a request as its worst case, to ask the application what it
 * answers: every rule of every input has failed, so the answer shows all a client can get back.
 *
 * @phpstan-import-type Rules from RequestRules
 */
final class ValidationSamples
{
    /**
     * The input the worst case of an unknown request is built on.
     */
    public const string ANY_INPUT = 'input';

    private const string LOCALE = 'en';

    /**
     * Two errors whose answers are merged: one where every rule failed, and one where every input
     * has a message added without a rule (`ValidationException::withMessages()`, an `after()` hook).
     *
     * @param  Rules  $rulesByInput
     * @return list<ValidationException>
     */
    public static function ofRequest(array $rulesByInput): array
    {
        $everyRuleFailed = self::validator();
        $messagesByHand = self::validator();

        foreach ($rulesByInput as $input => $rules) {
            $attribute = self::attribute((string) $input);
            $failed = [];

            foreach ($rules as [$rule, $parameters]) {
                $isObject = str_contains($rule, '\\');
                $name = $isObject ? $rule : ValidationRuleParser::parse($rule)[0];

                // A rule without a message never fails by itself: `nullable`, `sometimes`, `bail`.
                if (isset($failed[$name]) || (! $isObject && ! self::hasMessage($name))) {
                    continue;
                }

                $everyRuleFailed->fail($attribute, $name, array_map(strval(...), array_values(array_filter($parameters, is_scalar(...)))));
                $failed[$name] = true;
            }

            $messagesByHand->errors()->add($attribute, "The {$attribute} field is invalid.");
        }

        return array_values(array_filter(
            [new ValidationException($everyRuleFailed), new ValidationException($messagesByHand)],
            static fn (ValidationException $exception): bool => $exception->validator->errors()->isNotEmpty(),
        ));
    }

    /**
     * The error of a request nobody knows: one input that failed every rule Laravel has a message for.
     *
     * @return list<ValidationException>
     */
    public static function ofAnyRequest(): array
    {
        $lines = Lang::get('validation', [], self::LOCALE);
        $rules = [];

        foreach (is_array($lines) ? $lines : [] as $rule => $line) {
            // The group also holds the names of attributes and the messages of single inputs.
            if (is_string($rule) && ! in_array($rule, ['attributes', 'custom', 'values'], true)) {
                $rules[] = [$rule, []];
            }
        }

        return self::ofRequest([self::ANY_INPUT => $rules]);
    }

    /**
     * The name of an input as the answer reports it: `tags.*` fails as its first item, `tags.0`.
     */
    public static function attribute(string $input): string
    {
        return str_replace('*', '0', $input);
    }

    /**
     * A validator whose failures are set by hand instead of found by validating.
     */
    private static function validator(): Validator
    {
        return new class(app('translator'), [], []) extends Validator
        {
            /**
             * The message is unique per rule: the validator keeps a message of an input only once,
             * and an answer tells the rules of an input apart by their messages.
             *
             * @param  list<string>  $parameters
             */
            public function fail(string $attribute, string $rule, array $parameters): void
            {
                // First the message: reading the errors of a new validator resets its failed rules.
                $this->errors()->add($attribute, "The {$attribute} field failed the {$rule} rule.");
                $this->failedRules[$attribute][$rule] = $parameters;
            }
        };
    }

    /**
     * Only a rule with a message can fail. English first: the framework ships it complete, a
     * translation may lag behind.
     */
    private static function hasMessage(string $rule): bool
    {
        $line = 'validation.'.Str::snake($rule);

        return Lang::has($line, self::LOCALE) || Lang::has($line);
    }
}
