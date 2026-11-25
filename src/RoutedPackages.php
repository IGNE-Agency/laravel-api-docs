<?php

declare(strict_types=1);

namespace Igne\ApiDocs;

use Dedoc\Scramble\Infer\Configuration\DefinitionMatcher;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use ReflectionClass;

/**
 * Lets Scramble read a package that registers routes like the application itself.
 *
 * Scramble parses the controller, the Form Request and the Resource of a route wherever they live,
 * but any other class under `vendor/` only by its signatures. A Resource of a package whose values
 * come from another class of that package (a value object, a model) then loses their types. This
 * matches every class of a package that has a controller on a route, so its source is parsed too.
 *
 * A package is told by the first two parts of a class name (`Igne\Auth`). The framework and a
 * package without routes are left as they are.
 */
final class RoutedPackages implements DefinitionMatcher
{
    /**
     * @var array<string, true>|null
     */
    private ?array $packages = null;

    public function __construct(private readonly Router $router) {}

    public function matches(string $class): bool
    {
        $package = self::packageOf($class);

        return $package !== null && isset($this->packages()[$package]);
    }

    /**
     * The packages under `vendor/` with a controller on a route.
     *
     * @return array<string, true>
     */
    private function packages(): array
    {
        if ($this->packages !== null) {
            return $this->packages;
        }

        $packages = [];

        foreach ($this->router->getRoutes() as $route) {
            $controller = $route instanceof Route ? $route->getAction('controller') : null;
            $class = is_string($controller) ? explode('@', $controller)[0] : null;

            if ($class === null || ! class_exists($class) || ($package = self::packageOf($class)) === null) {
                continue;
            }

            $path = (new ReflectionClass($class))->getFileName();

            if ($path !== false && str_contains($path, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                $packages[$package] = true;
            }
        }

        return $this->packages = $packages;
    }

    private static function packageOf(string $class): ?string
    {
        $segments = explode('\\', ltrim($class, '\\'));

        return count($segments) > 2 ? $segments[0].'\\'.$segments[1] : null;
    }
}
