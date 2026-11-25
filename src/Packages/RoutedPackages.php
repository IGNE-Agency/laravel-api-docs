<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Packages;

use Dedoc\Scramble\Infer\Configuration\DefinitionMatcher;
use Illuminate\Routing\Router;

/**
 * Lets Scramble read a package with a controller on a route like the application: by its source,
 * not only its signatures. Without it a Resource of a package loses the types of the values it
 * takes from other classes of that package.
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
