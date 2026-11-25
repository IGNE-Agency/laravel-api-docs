<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Fortify;

use Closure;
use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Infer\Scope\GlobalScope;
use Dedoc\Scramble\Infer\Services\ReferenceTypeResolver;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Reference\MethodCallReferenceType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\Union;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response as HttpResponse;
use ReflectionClass;
use ReflectionFunction;

/**
 * Documents an action that answers through a response contract of the container, as Laravel
 * Fortify does: `return app(LoginResponse::class)`. Scramble reads the interface, which says
 * nothing; this follows it to the class bound to it and documents what that class answers a JSON
 * client.
 */
final class BoundResponses extends TypeToSchemaExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType && self::boundTo($type->name) !== null;
    }

    /**
     * @param  ObjectType  $type
     */
    public function toResponse(Type $type): Response|Reference|null
    {
        $bound = self::boundTo($type->name);
        $answer = $bound === null ? null : self::answerForJson($bound);

        return $answer === null ? null : $this->openApiTransformer->toResponse($answer);
    }

    /**
     * The part of `toResponse()` that is no redirect and no view. Null when there is none (the class
     * throws for a JSON client) or more than one.
     *
     * @param  class-string  $responsable
     */
    public static function answerForJson(string $responsable): ?Type
    {
        $toResponse = new MethodCallReferenceType(new ObjectType($responsable), 'toResponse', []);
        $answer = ReferenceTypeResolver::getInstance()->resolve(new GlobalScope, $toResponse);

        $forJson = array_values(array_filter(
            $answer instanceof Union ? $answer->types : [$answer],
            static fn (Type $answer): bool => $answer instanceof ObjectType && (
                $answer->isInstanceOf(JsonResponse::class)
                || $answer->isInstanceOf(HttpResponse::class)
                || $answer->isInstanceOf(JsonResource::class)
            ),
        ));

        return count($forJson) === 1 ? $forJson[0] : null;
    }

    /**
     * Null when the type is no contract, nothing is bound to it, or a closure builds the answer.
     *
     * @return class-string|null
     */
    public static function boundTo(string $contract): ?string
    {
        $isContract = interface_exists($contract) || (class_exists($contract) && (new ReflectionClass($contract))->isAbstract());

        if (! $isContract || ! is_a($contract, Responsable::class, true) || ! app()->bound($contract)) {
            return null;
        }

        $binding = app()->getBindings()[$contract]['concrete'] ?? null;

        // Laravel wraps a class bound by its name in a closure that carries the name along.
        $bound = $binding instanceof Closure ? ((new ReflectionFunction($binding))->getStaticVariables()['concrete'] ?? null) : null;

        return is_string($bound) && $bound !== $contract && class_exists($bound) ? $bound : null;
    }
}
