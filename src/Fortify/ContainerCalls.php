<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Fortify;

use Dedoc\Scramble\Infer\Extensions\Event\FunctionCallEvent;
use Dedoc\Scramble\Infer\Extensions\FunctionReturnTypeExtension;
use Dedoc\Scramble\Support\Type\GenericClassStringType;
use Dedoc\Scramble\Support\Type\NeverType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;

/**
 * Gives `app(LoginResponse::class)` and `resolve(...)` a type, which Scramble does not infer, so
 * {@see BoundResponses} can follow it.
 */
final class ContainerCalls implements FunctionReturnTypeExtension
{
    public function shouldHandle(string $name): bool
    {
        return in_array($name, ['app', 'resolve'], true);
    }

    public function getFunctionReturnType(FunctionCallEvent $event): ?Type
    {
        $abstract = $event->getArg('abstract', 0);

        if (! $abstract instanceof GenericClassStringType || ! $abstract->type instanceof ObjectType) {
            return null;
        }

        // A contract whose bound class has no answer for a JSON client only throws for one: the call never returns.
        $bound = BoundResponses::boundTo($abstract->type->name);

        if ($bound !== null && BoundResponses::answerForJson($bound) === null) {
            return new NeverType;
        }

        return new ObjectType($abstract->type->name);
    }
}
