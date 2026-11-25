<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Validation;

use Dedoc\Scramble\Contracts\AllRulesSchemasTransformer;
use Dedoc\Scramble\Support\OperationExtensions\RulesEvaluator\RuleType;
use Dedoc\Scramble\Support\RuleTransforming\NormalizedRule;
use Dedoc\Scramble\Support\RuleTransforming\RuleTransformerContext;
use Dedoc\Scramble\Support\RuleTransforming\SchemaBag;
use Dedoc\Scramble\Support\Type\ObjectType;

/**
 * Records the validation rules of the request Scramble is documenting. No schema is changed.
 *
 * Scramble reads the rules of an operation before it runs the operation transformers, so the rules
 * wait in a static property until the transformer that documents the validation error takes them.
 *
 * @phpstan-type Rules array<string, list<array{0: string, 1: list<mixed>}>> input => [rule name or class, parameters]
 */
final class RequestRules implements AllRulesSchemasTransformer
{
    /**
     * @var Rules
     */
    private static array $rules = [];

    public function shouldHandle(NormalizedRule $rule): bool
    {
        return true;
    }

    public function transformAll(SchemaBag $schemaBag, NormalizedRule $rule, RuleTransformerContext $context): void
    {
        $value = $rule->getRule();

        // A rule Scramble could only read from the source is a RuleType around the type of its object.
        $identity = match (true) {
            is_string($value) => $value,
            $value instanceof RuleType => $value->type instanceof ObjectType ? $value->type->name : null,
            default => $value::class,
        };

        if ($identity !== null) {
            self::$rules[$context->field][] = [$identity, array_values($rule->getParameters())];
        }
    }

    /**
     * The rules of the operation being documented, which are then forgotten.
     *
     * @return Rules
     */
    public static function take(): array
    {
        [$rules, self::$rules] = [self::$rules, []];

        return $rules;
    }
}
