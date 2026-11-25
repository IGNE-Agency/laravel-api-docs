<?php

declare(strict_types=1);

namespace Igne\ScrambleExtensions;

use Dedoc\Scramble\Infer\Extensions\ExpressionExceptionExtension;
use Dedoc\Scramble\Infer\Scope\LazyShallowReflectionIndex;
use Dedoc\Scramble\Infer\Scope\Scope;
use Dedoc\Scramble\Infer\Services\ShallowTypeResolver;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\TemplateType;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use ReflectionClass;

/**
 * Lets an exception be thrown anywhere: Scramble only sees what a method throws itself, so for
 * every call a method makes to the application's own code this adds what the called method
 * throws. Scramble asks again while it analyses that method, so the calls are followed all the
 * way down: controller, service, repository.
 *
 * Only a call whose target is known from the code is followed: a typed property or argument, a
 * static call, `new`, `$this`. The application's code is followed, and so is a package inside itself. What runs behind an interface, the container (`app(Service::class)`),
 * an event or a job is not; such a method declares its exceptions with `@throws`.
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

        if ($class === null || ! $node->name instanceof Identifier || ! $this->isReadable($class, $scope->classDefinition()?->name)) {
            return [];
        }

        $method = $class.'::'.$node->name->name;

        if (isset(self::$following[$method])) {
            return [];
        }

        self::$following[$method] = true;

        try {
            return Scramble::infer()->analyzeClass($class)->getMethodDefinition($node->name->name)?->type->exceptions ?? [];
        } finally {
            unset(self::$following[$method]);
        }
    }

    /**
     * The class a call is made on, when the code tells: the declared type of the object of a
     * method call, or the class named in a static call.
     */
    private function calledClass(Expr $node, Scope $scope): ?string
    {
        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            $object = (new ShallowTypeResolver(app(LazyShallowReflectionIndex::class)))->resolve($scope, $scope->getType($node->var));
            $object = $object instanceof TemplateType ? $object->is : $object;

            return $object instanceof ObjectType ? $object->name : null;
        }

        if ($node instanceof Expr\StaticCall && $node->class instanceof Name) {
            return in_array($node->class->toLowerString(), ['self', 'static'], true)
                ? $scope->classDefinition()?->name
                : $node->class->toString();
        }

        return null;
    }

    /**
     * The application's own code, and a call a package makes inside itself: a controller of a
     * package that calls a service of the same package is followed like the application's. The
     * framework and any other package document their exceptions with `@throws`; their code is not
     * read.
     */
    private function isReadable(string $class, ?string $caller): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        $path = (new ReflectionClass($class))->getFileName();

        if ($path === false) {
            return false;
        }

        if (! str_contains($path, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
            return true;
        }

        return $caller !== null && self::package($caller) !== null && self::package($caller) === self::package($class);
    }

    /**
     * The vendor and package part of a class name (`Igne\Auth`), null for a class without one.
     */
    private static function package(string $class): ?string
    {
        $segments = explode('\\', ltrim($class, '\\'));

        return count($segments) > 2 ? $segments[0].'\\'.$segments[1] : null;
    }
}
