<?php

declare(strict_types=1);

namespace Igne\ScrambleExtensions;

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
 * Documents an action that answers through a response contract: `return app(LoginResponse::class)`.
 *
 * A package such as Laravel Fortify lets an application replace each of its answers: its
 * controllers return an interface, and the container decides which class answers. Scramble reads
 * the interface, which says nothing. This follows the contract to the class that is bound to it,
 * Fortify's own or the one an application or a package put in its place, and documents what that
 * class answers a JSON client: the part of its `toResponse()` that is no redirect and no view.
 *
 * Nothing here is about one package: any controller that answers through a bound contract is read
 * this way.
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
     * What a class answers a JSON client: the part of its `toResponse()` that is no redirect and no
     * view. Null when it has no such answer (it throws for a JSON client), or more than one.
     *
     * @param  class-string  $responsable
     */
    public static function answerForJson(string $responsable): ?Type
    {
        $answer = ReferenceTypeResolver::getInstance()->resolve(new GlobalScope, new MethodCallReferenceType(new ObjectType($responsable), 'toResponse', []));

        $forJson = array_values(array_filter(
            $answer instanceof Union ? $answer->types : [$answer],
            static fn (Type $answer): bool => $answer instanceof ObjectType
                && ($answer->isInstanceOf(JsonResponse::class) || $answer->isInstanceOf(HttpResponse::class) || $answer->isInstanceOf(JsonResource::class)),
        ));

        return count($forJson) === 1 ? $forJson[0] : null;
    }

    /**
     * The class the container answers a response contract with: null when the type is no contract
     * (a class that answers itself), when nothing is bound to it, or when a closure builds the answer.
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
