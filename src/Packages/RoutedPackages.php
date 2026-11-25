<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Packages;

use Dedoc\Scramble\Infer\Configuration\DefinitionMatcher;
use Illuminate\Routing\Router;

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
        $package = Package::of($class);

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
            $class = $route->getControllerClass();

            if ($class !== null && Package::isInVendor($class) === true && ($package = Package::of($class)) !== null) {
                $packages[$package] = true;
            }
        }

        return $this->packages = $packages;
    }
}
