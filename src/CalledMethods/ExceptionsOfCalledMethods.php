<?php

declare(strict_types=1);

namespace Igne\ApiDocs\CalledMethods;

use Dedoc\Scramble\Infer\Extensions\ExpressionExceptionExtension;
use Dedoc\Scramble\Infer\Scope\LazyShallowReflectionIndex;
use Dedoc\Scramble\Infer\Scope\Scope;
use Dedoc\Scramble\Infer\Services\ShallowTypeResolver;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\TemplateType;
use Igne\ApiDocs\Packages\Package;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

/**
 * Adds what the methods an action calls can throw, all the way down (controller, service,
 * repository): Scramble only sees what a method throws itself.
 *
 * A call is followed when the code tells its target (a typed property or argument, a static call,
 * `new`, `$this`, an invoked action class) and that target is application code, or code of the
 * same package as the caller. Anything else declares its exceptions with `@throws`.
 */
final class ExceptionsOfCalledMethods implements ExpressionExceptionExtension
{
    /**
     * The methods being followed, so two methods that call each other end.
     *
     * @var array<string, true>
     */
    private static array $following = [];

    public function getException(Expr $node, Scope $scope): array
    {
        $class = $this->calledClass($node, $scope);
        $name = $this->calledMethod($node);

        if ($class === null || $name === null || ! $this->isReadable($class, $scope->classDefinition()?->name)) {
            return [];
        }

        $method = $class.'::'.$name;

        if (isset(self::$following[$method])) {
            return [];
        }

        self::$following[$method] = true;

        try {
            return Scramble::infer()->analyzeClass($class)->getMethodDefinition($name)?->type->exceptions ?? [];
        } finally {
            unset(self::$following[$method]);
        }
    }

    /**
     * `__invoke` when an object itself is called: `$action($user)`.
     */
    private function calledMethod(Expr $node): ?string
    {
        if ($node instanceof Expr\FuncCall) {
            return $node->name instanceof Expr ? '__invoke' : null;
        }

        $isMethodCall = $node instanceof Expr\MethodCall
            || $node instanceof Expr\NullsafeMethodCall
            || $node instanceof Expr\StaticCall;

        return $isMethodCall && $node->name instanceof Identifier ? $node->name->name : null;
    }

    /**
     * Null when the code does not tell which class is called.
     */
    private function calledClass(Expr $node, Scope $scope): ?string
    {
        $object = match (true) {
            $node instanceof Expr\MethodCall, $node instanceof Expr\NullsafeMethodCall => $node->var,
            $node instanceof Expr\FuncCall && $node->name instanceof Expr => $node->name,
            default => null,
        };

        if ($object !== null) {
            $type = (new ShallowTypeResolver(app(LazyShallowReflectionIndex::class)))->resolve($scope, $scope->getType($object));
            $type = $type instanceof TemplateType ? $type->is : $type;

            return $type instanceof ObjectType ? $type->name : null;
        }

        if ($node instanceof Expr\StaticCall && $node->class instanceof Name) {
            return in_array($node->class->toLowerString(), ['self', 'static'], true)
                ? $scope->classDefinition()?->name
                : $node->class->toString();
        }

        return null;
    }

    /**
     * Application code, or a package calling inside itself.
     */
    private function isReadable(string $class, ?string $caller): bool
    {
        return match (Package::isInVendor($class)) {
            null => false,
            false => true,
            true => $caller !== null && Package::of($class) !== null && Package::of($class) === Package::of($caller),
        };
    }
}
