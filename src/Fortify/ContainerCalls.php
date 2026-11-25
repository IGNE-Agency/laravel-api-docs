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
 * Tells Scramble what the container hands back: `app(LoginResponse::class)` and
 * `resolve(LoginResponse::class)` are a `LoginResponse`. Without it such a call has no type, and an
 * action that answers with one is documented by its docblock alone. {@see BoundResponses} then
 * follows the contract to the class that is bound to it.
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

        // A response contract whose bound class has no answer for a JSON client only throws for one (a failed
        // confirmation answers a redirect, or a validation error): for the document, such a call never returns.
        $bound = BoundResponses::boundTo($abstract->type->name);

        if ($bound !== null && BoundResponses::answerForJson($bound) === null) {
            return new NeverType;
        }

        return new ObjectType($abstract->type->name);
    }
}
